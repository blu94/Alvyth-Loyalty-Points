<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Repositories\LoyaltyMemberRepository;
use Tests\TestCase;

/**
 * The Overview's headline figures.
 *
 * Counted by what an entry means, not by its sign. "Every positive" and "every negative" is
 * the obvious split and told two lies: a refund's positive mirror read as the shop issuing
 * more points, and expiry — being negative — was reported as customers redeeming. Both nets
 * came out right, which is why neither was visible.
 *
 * The invariant that has to hold whatever the definitions are: **issued − redeemed − expired
 * equals the whole ledger**, so the tiles add up on screen and agree with every member's
 * balance.
 */
class OverviewFiguresTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->purge();
    }

    protected function tearDown(): void
    {
        $this->purge();
        parent::tearDown();
    }

    private function purge(): void
    {
        LoyaltyTransaction::whereIn('member_id', LoyaltyMember::query()->select('id'))->forceDelete();
        LoyaltyMember::query()->forceDelete();
    }

    private function member(): LoyaltyMember
    {
        return LoyaltyMember::create([
            'user_id'         => User::factory()->create(['status' => 'active'])->id,
            'status'          => 'active',
            'joined_at'       => now(),
            'balance'         => 0,
            'lifetime_points' => 0,
        ]);
    }

    private function entry(LoyaltyMember $m, int $points, string $type, ?int $reverses = null): LoyaltyTransaction
    {
        return LoyaltyTransaction::create([
            'member_id'   => $m->id,
            'points'      => $points,
            'type'        => $type,
            'reason'      => 'test',
            'reverses_id' => $reverses,
        ]);
    }

    private function figures(): array
    {
        return app(LoyaltyMemberRepository::class)->pageData('overview');
    }

    private function assertReconciles(array $f): void
    {
        $ledger = (int) LoyaltyTransaction::sum('points');

        $this->assertSame(
            $ledger,
            $f['points_issued'] - $f['points_redeemed'] - $f['points_expired'],
            'issued − redeemed − expired must equal the whole ledger.'
        );
        $this->assertSame($ledger, $f['points_outstanding']);
    }

    #[Test]
    public function earning_and_spending_are_counted_separately(): void
    {
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($m, -400, LoyaltyTransaction::TYPE_REDEEM);

        $f = $this->figures();

        $this->assertSame(1000, $f['points_issued']);
        $this->assertSame(400, $f['points_redeemed']);
        $this->assertSame(0, $f['points_expired']);
        $this->assertSame(600, $f['points_outstanding']);
        $this->assertReconciles($f);
    }

    #[Test]
    public function expiry_is_not_reported_as_redemption(): void
    {
        // The opposite conclusion about how well the programme is working: points that ran
        // out unused are not points a customer chose to spend.
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($m, -300, LoyaltyTransaction::TYPE_EXPIRE);

        $f = $this->figures();

        $this->assertSame(0, $f['points_redeemed'], 'Expired points were never redeemed.');
        $this->assertSame(300, $f['points_expired']);
        $this->assertSame(700, $f['points_outstanding']);
        $this->assertReconciles($f);
    }

    #[Test]
    public function a_refunded_earn_is_not_counted_as_issued(): void
    {
        $m    = $this->member();
        $earn = $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($m, -1000, LoyaltyTransaction::TYPE_ADJUST, $earn->id);

        $f = $this->figures();

        $this->assertSame(0, $f['points_issued'], 'A purchase that was undone issued nothing.');
        $this->assertSame(0, $f['points_outstanding']);
        $this->assertReconciles($f);
    }

    #[Test]
    public function a_refunded_redemption_is_not_counted_as_issued(): void
    {
        // The lie the old sign-based split told: the positive mirror of a redemption read as
        // the shop handing out more points.
        $m      = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN);
        $redeem = $this->entry($m, -400, LoyaltyTransaction::TYPE_REDEEM);
        $this->entry($m, 400, LoyaltyTransaction::TYPE_ADJUST, $redeem->id);

        $f = $this->figures();

        $this->assertSame(1000, $f['points_issued'], 'Returning spent points is not issuing new ones.');
        $this->assertSame(0, $f['points_redeemed'], 'A refunded redemption is not a redemption.');
        $this->assertSame(1000, $f['points_outstanding']);
        $this->assertReconciles($f);
    }

    #[Test]
    public function an_operator_adjustment_counts_as_issued_in_both_directions(): void
    {
        $m = $this->member();
        $this->entry($m, 500, LoyaltyTransaction::TYPE_ADJUST);
        $this->entry($m, -200, LoyaltyTransaction::TYPE_ADJUST);

        $f = $this->figures();

        $this->assertSame(300, $f['points_issued']);
        $this->assertReconciles($f);
    }

    #[Test]
    public function the_figures_reconcile_across_the_whole_lifecycle(): void
    {
        $m      = $this->member();
        $earn   = $this->entry($m, 1200, LoyaltyTransaction::TYPE_EARN);
        $redeem = $this->entry($m, -1000, LoyaltyTransaction::TYPE_REDEEM);
        $this->entry($m, 240, LoyaltyTransaction::TYPE_EARN);
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_ADJUST, $redeem->id);
        $this->entry($m, -1200, LoyaltyTransaction::TYPE_ADJUST, $earn->id);
        $this->entry($m, -100, LoyaltyTransaction::TYPE_EXPIRE);

        $f = $this->figures();

        $this->assertReconciles($f);
        $this->assertSame((int) $m->recalculate()->refresh()->balance, $f['points_outstanding']);
    }

    #[Test]
    public function liability_prices_the_outstanding_balance(): void
    {
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($m, -300, LoyaltyTransaction::TYPE_EXPIRE);

        // 700 outstanding at the default 0.01 a point.
        $this->assertSame('7.00', $this->figures()['liability']);
    }
}

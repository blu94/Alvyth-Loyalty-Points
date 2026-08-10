<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\PointsExpiry;
use Tests\TestCase;

/**
 * Retiring points nobody spent in time.
 *
 * Every case here takes something away from a customer, so the arithmetic has to be right in
 * the mean direction as well as the generous one. The rule under test: points credited before
 * the cutoff, less everything ever spent — which is oldest-first without a per-batch ledger,
 * because spending always consumes the oldest points first.
 */
class PointsExpiryTest extends TestCase
{
    use DatabaseTransactions;

    private function policy(?int $months): void
    {
        LoyaltySetting::current()->fill(['expiry_months' => $months])->save();
    }

    private function member(string $status = 'active'): LoyaltyMember
    {
        $user = User::factory()->create(['status' => 'active']);

        return LoyaltyMember::create([
            'user_id'         => $user->id,
            'status'          => $status,
            'joined_at'       => now()->subYears(3),
            'balance'         => 0,
            'lifetime_points' => 0,
        ]);
    }

    /** An entry backdated to `$monthsAgo`, because age is the whole subject. */
    private function entry(LoyaltyMember $m, int $points, string $type, int $monthsAgo): LoyaltyTransaction
    {
        $row = LoyaltyTransaction::create([
            'member_id' => $m->id,
            'points'    => $points,
            'type'      => $type,
            'reason'    => 'test',
        ]);

        $when = now()->subMonths($monthsAgo);
        $row->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $row;
    }

    private function expire(): array
    {
        return app(PointsExpiry::class)->run();
    }

    #[Test]
    public function points_older_than_the_window_are_retired(): void
    {
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 18);
        $m->recalculate();

        $result = $this->expire();
        $m->refresh();

        $this->assertSame(1000, $result['expired_points']);
        $this->assertSame(1, $result['expired_members']);
        $this->assertSame(0, $m->balance);
    }

    #[Test]
    public function recent_points_are_left_alone(): void
    {
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 3);
        $m->recalculate();

        $this->expire();

        $this->assertSame(1000, $m->refresh()->balance);
    }

    #[Test]
    public function only_the_part_older_than_the_window_goes(): void
    {
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 400, LoyaltyTransaction::TYPE_EARN, 20);
        $this->entry($m, 600, LoyaltyTransaction::TYPE_EARN, 2);
        $m->recalculate();

        $this->expire();

        $this->assertSame(600, $m->refresh()->balance);
    }

    #[Test]
    public function spending_consumes_the_oldest_points_first(): void
    {
        // 400 old + 600 recent, and they already spent 400. That redemption is treated as
        // having used the old points, so nothing is left to expire — the customer keeps the
        // 600 they earned recently. Charging them again for points they already spent is the
        // failure this guards.
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 400, LoyaltyTransaction::TYPE_EARN, 20);
        $this->entry($m, 600, LoyaltyTransaction::TYPE_EARN, 2);
        $this->entry($m, -400, LoyaltyTransaction::TYPE_REDEEM, 1);
        $m->recalculate();

        $result = $this->expire();

        $this->assertSame(0, $result['expired_points']);
        $this->assertSame(600, $m->refresh()->balance);
    }

    #[Test]
    public function a_partial_spend_leaves_the_remainder_expirable(): void
    {
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 20);
        $this->entry($m, -300, LoyaltyTransaction::TYPE_REDEEM, 1);
        $m->recalculate();

        $this->expire();

        $this->assertSame(0, $m->refresh()->balance);
        $this->assertSame(
            -700,
            (int) $m->transactions()->where('type', LoyaltyTransaction::TYPE_EXPIRE)->sum('points')
        );
    }

    #[Test]
    public function expiry_takes_balance_but_never_standing(): void
    {
        // Lifetime counts what was earned. Expiry removes points, not the fact they were
        // earned, so a customer does not lose their tier for not spending fast enough.
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 20);
        $m->recalculate();

        $this->expire();
        $m->refresh();

        $this->assertSame(0, $m->balance);
        $this->assertSame(1000, $m->lifetime_points);
    }

    #[Test]
    public function no_policy_means_nothing_expires(): void
    {
        $this->policy(null);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 60);
        $m->recalculate();

        $result = $this->expire();

        $this->assertNull($result['months']);
        $this->assertSame(1000, $m->refresh()->balance);
    }

    #[Test]
    public function a_suspended_member_is_left_untouched(): void
    {
        // Their balance is frozen. Freezing it while it quietly drains would be the worst of
        // both, and mirrors the rule redemption already enforces.
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 20);
        $m->recalculate();
        $m->update(['status' => 'suspended']);

        $this->expire();

        $this->assertSame(1000, $m->refresh()->balance);
    }

    #[Test]
    public function running_it_twice_expires_nothing_the_second_time(): void
    {
        // The expiry entry it writes is itself a debit, so a second run finds the old credits
        // already consumed. Without that, every press would re-expire the same points.
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 20);
        $m->recalculate();

        $this->expire();
        $second = $this->expire();

        $this->assertSame(0, $second['expired_points']);
        $this->assertSame(0, $m->refresh()->balance);
        $this->assertSame(1, $m->transactions()->where('type', LoyaltyTransaction::TYPE_EXPIRE)->count());
    }

    #[Test]
    public function it_writes_a_ledger_entry_rather_than_editing_the_balance(): void
    {
        $this->policy(12);
        $m = $this->member();
        $this->entry($m, 1000, LoyaltyTransaction::TYPE_EARN, 20);
        $m->recalculate();

        $this->expire();

        $entry = $m->transactions()->where('type', LoyaltyTransaction::TYPE_EXPIRE)->first();

        $this->assertNotNull($entry, 'Expiry must be visible in the customer’s own history.');
        $this->assertSame(-1000, $entry->points);
        $this->assertSame((int) $m->transactions()->sum('points'), $m->refresh()->balance);
    }
}

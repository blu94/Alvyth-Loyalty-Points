<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Tests\TestCase;

/**
 * `LoyaltyMember::recalculate()` — the rule that decides what a customer is worth.
 *
 * The ledger is the only source of truth: `balance` and `lifetime_points` are recomputed from
 * it after every write, never incremented. So every bug in this class is a bug in what the
 * customer is told they have, and several of them look identical on screen to working
 * software — a tier is just a number that came out of a sum.
 *
 * The two totals are separate on purpose. Balance is spendable and falls on redemption;
 * lifetime measures earning and never falls from spending, so cashing in a reward cannot
 * demote someone who has been loyal for years.
 *
 * Run these against a container with the plugin installed into the test database:
 *
 *   docker exec -e DB_DATABASE=alvyth_test alvyth_app \
 *     php artisan plugin:import /var/www/storage/app/plugin-src-tmp/loyalty-points --enable
 *   docker exec alvyth_app php vendor/bin/phpunit \
 *     storage/app/plugins/loyalty-points/tests --no-coverage
 */
class LoyaltyLedgerTest extends TestCase
{
    use DatabaseTransactions;

    private function member(): LoyaltyMember
    {
        $user = User::factory()->create(['status' => 'active']);

        return LoyaltyMember::create([
            'user_id'         => $user->id,
            'status'          => 'active',
            'joined_at'       => now(),
            'balance'         => 0,
            'lifetime_points' => 0,
        ]);
    }

    private function entry(LoyaltyMember $member, int $points, string $type, ?int $reverses = null): LoyaltyTransaction
    {
        return LoyaltyTransaction::create([
            'member_id'   => $member->id,
            'points'      => $points,
            'type'        => $type,
            'reason'      => 'test',
            'reverses_id' => $reverses,
        ]);
    }

    #[Test]
    public function earning_raises_both_totals(): void
    {
        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);

        $member->recalculate()->refresh();

        $this->assertSame(1200, $member->balance);
        $this->assertSame(1200, $member->lifetime_points);
    }

    #[Test]
    public function spending_lowers_the_balance_but_never_the_standing(): void
    {
        // The entire reason the two numbers exist.
        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $this->entry($member, -1000, LoyaltyTransaction::TYPE_REDEEM);

        $member->recalculate()->refresh();

        $this->assertSame(200, $member->balance);
        $this->assertSame(1200, $member->lifetime_points);
    }

    #[Test]
    public function reversing_an_earn_lowers_standing(): void
    {
        // A purchase that was undone did not earn anything, so the standing it bought goes.
        $member = $this->member();
        $earn   = $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $this->entry($member, -1200, LoyaltyTransaction::TYPE_ADJUST, $earn->id);

        $member->recalculate()->refresh();

        $this->assertSame(0, $member->balance);
        $this->assertSame(0, $member->lifetime_points);
    }

    #[Test]
    public function reversing_a_redemption_restores_balance_without_inventing_earning(): void
    {
        // The bug this rule was written for: a positive mirror of a redemption is money
        // handed back, not points earned. Counting it let a refund promote a customer.
        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $redeem = $this->entry($member, -1000, LoyaltyTransaction::TYPE_REDEEM);
        $this->entry($member, 1000, LoyaltyTransaction::TYPE_ADJUST, $redeem->id);

        $member->recalculate()->refresh();

        $this->assertSame(1200, $member->balance);
        $this->assertSame(1200, $member->lifetime_points, 'A refund must not raise standing.');
    }

    #[Test]
    public function a_refund_does_not_change_a_different_members_standing(): void
    {
        // Regression. Written as `->where(A)->orWhere(B)` the relation's own `member_id = ?`
        // bound to the first branch only — `(member_id = ? AND A) OR B` — so B matched every
        // reversal in the table. One stranger's refund summed into this member and floored
        // their lifetime to zero, demoting them out of their tier. Nothing on screen would
        // have said why.
        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $member->recalculate()->refresh();

        $stranger = $this->member();
        $earn     = $this->entry($stranger, 5000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($stranger, -5000, LoyaltyTransaction::TYPE_ADJUST, $earn->id);

        $member->recalculate()->refresh();

        $this->assertSame(1200, $member->lifetime_points);
        $this->assertSame(1200, $member->balance);
    }

    #[Test]
    public function the_full_earn_spend_refund_repay_cycle_holds(): void
    {
        $member = $this->member();

        $earn = $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $member->recalculate()->refresh();
        $this->assertSame([1200, 1200], [$member->balance, $member->lifetime_points]);

        // Paid: spends 1,000 and earns 240 on the new order.
        $redeem  = $this->entry($member, -1000, LoyaltyTransaction::TYPE_REDEEM);
        $earned2 = $this->entry($member, 240, LoyaltyTransaction::TYPE_EARN);
        $member->recalculate()->refresh();
        $this->assertSame([440, 1440], [$member->balance, $member->lifetime_points]);

        // Refunded: both sides of that order are mirrored.
        $this->entry($member, 1000, LoyaltyTransaction::TYPE_ADJUST, $redeem->id);
        $this->entry($member, -240, LoyaltyTransaction::TYPE_ADJUST, $earned2->id);
        $member->recalculate()->refresh();

        $this->assertSame(1200, $member->balance);
        $this->assertSame(1200, $member->lifetime_points, 'A refund must return standing to where it was.');
        $this->assertNotSame(2440, $member->lifetime_points);
    }

    #[Test]
    public function an_operator_adjustment_counts_toward_standing_in_both_directions(): void
    {
        // `adjust` is the one type whose sign is the author's intent — it is how a mistake is
        // corrected, so it has to move standing or the ledger and the tier disagree.
        $member = $this->member();
        $this->entry($member, 500, LoyaltyTransaction::TYPE_ADJUST);
        $this->entry($member, -200, LoyaltyTransaction::TYPE_ADJUST);

        $member->recalculate()->refresh();

        $this->assertSame(300, $member->balance);
        $this->assertSame(300, $member->lifetime_points);
    }

    #[Test]
    public function an_expiry_takes_balance_without_rewriting_history(): void
    {
        $member = $this->member();
        $this->entry($member, 1000, LoyaltyTransaction::TYPE_EARN);
        $this->entry($member, -400, LoyaltyTransaction::TYPE_EXPIRE);

        $member->recalculate()->refresh();

        $this->assertSame(600, $member->balance);
        $this->assertSame(1000, $member->lifetime_points, 'Expiry removes points, not the fact they were earned.');
    }

    #[Test]
    public function lifetime_is_floored_at_zero(): void
    {
        $member = $this->member();
        $earn   = $this->entry($member, 100, LoyaltyTransaction::TYPE_EARN);
        $this->entry($member, -100, LoyaltyTransaction::TYPE_ADJUST, $earn->id);
        $this->entry($member, -500, LoyaltyTransaction::TYPE_ADJUST);

        $member->recalculate()->refresh();

        $this->assertSame(0, $member->lifetime_points);
    }

    #[Test]
    public function standing_follows_lifetime_and_ignores_switched_off_tiers(): void
    {
        $silver = LoyaltyTier::create(['title' => 'Silver ' . uniqid(), 'threshold' => 500, 'status' => 'active']);
        $gold   = LoyaltyTier::create(['title' => 'Gold ' . uniqid(), 'threshold' => 1000, 'status' => 'inactive']);

        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $member->recalculate()->refresh();

        // Gold's threshold is met, but switching a tier off must stop it being awarded
        // without having to delete its definition.
        $this->assertSame($silver->id, $member->tier_id);

        $gold->update(['status' => 'active']);
        $member->recalculate()->refresh();

        $this->assertSame($gold->id, $member->tier_id);
    }

    #[Test]
    public function spending_down_never_costs_a_tier(): void
    {
        $gold = LoyaltyTier::create(['title' => 'Gold ' . uniqid(), 'threshold' => 1000, 'status' => 'active']);

        $member = $this->member();
        $this->entry($member, 1200, LoyaltyTransaction::TYPE_EARN);
        $this->entry($member, -1200, LoyaltyTransaction::TYPE_REDEEM);

        $member->recalculate()->refresh();

        $this->assertSame(0, $member->balance);
        $this->assertSame($gold->id, $member->tier_id, 'Spending a reward must not demote anyone.');
    }
}

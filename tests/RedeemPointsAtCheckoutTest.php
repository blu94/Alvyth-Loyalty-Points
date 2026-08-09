<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Events\CheckoutAdjusting;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Listeners\RedeemPointsAtCheckout;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Tests\TestCase;

/**
 * Spending points at checkout.
 *
 * Every case here is money. The listener only ever *proposes* — core clamps the total and
 * owns the rest of the arithmetic — so what these protect is that the proposal is honest:
 * never more than the customer holds, never more than the order is worth, and never a charge
 * for points that bought nothing.
 *
 * Nothing is deducted at this stage. The ledger entry is written when the order is paid, so
 * an abandoned checkout costs the customer nothing; that is `SpendPointsOnPaidOrder`'s job
 * and deliberately not this one's.
 */
class RedeemPointsAtCheckoutTest extends TestCase
{
    use DatabaseTransactions;

    private function settings(float $redeemValue = 0.01, int $minimum = 0): void
    {
        LoyaltySetting::current()->fill([
            'redeem_value'       => $redeemValue,
            'minimum_redemption' => $minimum,
        ])->save();
    }

    private function member(int $balance, string $status = 'active'): LoyaltyMember
    {
        $user = User::factory()->create(['status' => 'active']);

        $member = LoyaltyMember::create([
            'user_id'         => $user->id,
            'status'          => $status,
            'joined_at'       => now(),
            'balance'         => 0,
            'lifetime_points' => 0,
        ]);

        if ($balance > 0) {
            LoyaltyTransaction::create([
                'member_id' => $member->id,
                'points'    => $balance,
                'type'      => LoyaltyTransaction::TYPE_EARN,
                'reason'    => 'test',
            ]);
            $member->recalculate();
        }

        // Status is set after the balance is built: a suspended member still has a ledger.
        $member->update(['status' => $status]);

        return $member->refresh();
    }

    private function ask(?LoyaltyMember $member, float $subtotal, $points, float $discount = 0): CheckoutAdjusting
    {
        $event = new CheckoutAdjusting(
            [],
            $subtotal,
            $discount,
            $member ? User::find($member->user_id) : null,
            $points === null ? [] : ['plugin_fields' => ['loyalty_points' => (string) $points]],
        );

        (new RedeemPointsAtCheckout())->onCheckoutAdjusting($event);

        return $event;
    }

    #[Test]
    public function it_offers_the_points_the_customer_asked_for(): void
    {
        $this->settings();
        $event = $this->ask($this->member(1200), 250.00, 1000);

        $this->assertSame(10.0, $event->adjustmentTotal());
        $this->assertSame('loyalty-points', $event->adjustments[0]['code']);
        $this->assertSame(1000, $event->adjustments[0]['meta']['points']);
    }

    #[Test]
    public function it_never_spends_more_than_the_customer_holds(): void
    {
        $this->settings();
        $event = $this->ask($this->member(300), 250.00, 999999);

        $this->assertSame(3.0, $event->adjustmentTotal());
        $this->assertSame(300, $event->adjustments[0]['meta']['points']);
    }

    #[Test]
    public function it_charges_only_the_points_the_order_could_use(): void
    {
        // Clamping the value without clamping the points would bill 1,200 points for 5.00 of
        // relief on a 5.00 order — the customer would lose the rest for nothing.
        $this->settings();
        $event = $this->ask($this->member(1200), 5.00, 1200);

        $this->assertSame(5.0, $event->adjustmentTotal());
        $this->assertSame(500, $event->adjustments[0]['meta']['points']);
    }

    #[Test]
    public function it_sizes_the_offer_against_what_a_discount_code_already_took(): void
    {
        $this->settings();
        $event = $this->ask($this->member(10000), 100.00, 10000, discount: 60.00);

        $this->assertSame(40.0, $event->adjustmentTotal());
        $this->assertSame(4000, $event->adjustments[0]['meta']['points']);
    }

    #[Test]
    public function a_guest_is_offered_nothing(): void
    {
        $this->settings();
        $event = $this->ask(null, 250.00, 1000);

        $this->assertSame([], $event->adjustments);
    }

    #[Test]
    public function a_suspended_member_keeps_their_balance_and_stops_spending_it(): void
    {
        $this->settings();
        $event = $this->ask($this->member(1200, 'suspended'), 250.00, 1000);

        $this->assertSame([], $event->adjustments);
    }

    #[Test]
    public function asking_for_nothing_spends_nothing(): void
    {
        // Opt-in per order: a programme that silently spends someone's balance on their next
        // purchase has taken the choice away.
        $this->settings();

        $this->assertSame([], $this->ask($this->member(1200), 250.00, 0)->adjustments);
        $this->assertSame([], $this->ask($this->member(1200), 250.00, null)->adjustments);
    }

    #[Test]
    public function it_respects_the_minimum_redemption(): void
    {
        $this->settings(minimum: 500);

        $this->assertSame([], $this->ask($this->member(1200), 250.00, 100)->adjustments);

        // The floor is on what they spend, not on what they own.
        $event = $this->ask($this->member(600), 250.00, 600);
        $this->assertSame(6.0, $event->adjustmentTotal());
    }

    #[Test]
    public function a_shop_with_redemption_switched_off_offers_nothing(): void
    {
        $this->settings(redeemValue: 0);

        $this->assertSame([], $this->ask($this->member(1200), 250.00, 1000)->adjustments);
    }

    #[Test]
    public function it_writes_no_ledger_entry(): void
    {
        // Pricing is not payment. Debiting here would invent a refund path for every
        // abandoned checkout.
        $this->settings();
        $member = $this->member(1200);
        $before = $member->transactions()->count();

        $this->ask($member, 250.00, 1000);

        $this->assertSame($before, $member->transactions()->count());
        $this->assertSame(1200, $member->refresh()->balance);
    }
}

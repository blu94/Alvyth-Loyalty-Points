<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Events\CheckoutAdjusting;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Listeners\AwardPointsOnPaidOrder;
use Plugin\LoyaltyPoints\Backend\Listeners\RedeemPointsAtCheckout;
use Plugin\LoyaltyPoints\Backend\Listeners\ReversePointsOnRefundedOrder;
use Plugin\LoyaltyPoints\Backend\Listeners\SpendPointsOnPaidOrder;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Repositories\LoyaltyMemberRepository;
use Plugin\LoyaltyPoints\Backend\Repositories\LoyaltyTransactionRepository;
use Tests\TestCase;

/**
 * The boundary the ledger meets the outside world at.
 *
 * The suite that existed before this one covered the ledger's semantics thoroughly — earn,
 * redeem, expiry, adjustment, refund, re-payment, tier standing, overview reconciliation,
 * checkout clamping — and every one of those tests passed while a customer could spend the
 * same points twice and one redemption in eight silently charged a point too few. None of
 * them touched the boundary, which is exactly where money is lost: concurrency, floating
 * point, and a payment that arrives more than once.
 *
 * One test per defect, which is the ordinary discipline and the reason these stay fixed.
 */
class MoneyBoundaryTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        LoyaltySetting::current()->fill([
            'points_per_currency'    => 1,
            'earn_base'              => LoyaltySetting::BASE_GRAND_TOTAL,
            'redeem_value'           => 0.01,
            'minimum_redemption'     => 0,
            'expiry_months'          => null,
            'max_redemption_percent' => null,
        ])->save();
    }

    // ------------------------------------------------------------------
    // LP-01 — the same points cannot be spent twice
    // ------------------------------------------------------------------

    #[Test]
    public function two_orders_redeeming_one_balance_cannot_drive_it_negative(): void
    {
        // Earning switched off so the two orders cannot quietly re-fund the balance they are
        // spending; this is about the debit path alone.
        LoyaltySetting::current()->fill(['points_per_currency' => 0])->save();

        $user   = $this->customer();
        $member = $this->enrol($user, 500);

        // Both were quoted while the balance was 500, which is what the checkout guard saw.
        // Neither is paid yet, so nothing has been debited and both quotes look fundable.
        $first  = $this->order($user, 5.00, $this->redemption($member, 500));
        $second = $this->order($user, 5.00, $this->redemption($member, 500));

        $this->paid($first);
        $this->paid($second);

        $member->refresh();

        $this->assertSame(
            0,
            (int) $member->balance,
            'A balance may never go negative: the shop would have given away value it never issued.'
        );
        $this->assertSame(
            -500,
            (int) LoyaltyTransaction::where('member_id', $member->id)
                ->where('type', LoyaltyTransaction::TYPE_REDEEM)->sum('points'),
            'Only the points the member actually held may be debited across both orders.'
        );
    }

    #[Test]
    public function a_shortfall_is_recorded_on_the_order_rather_than_absorbed_in_silence(): void
    {
        LoyaltySetting::current()->fill(['points_per_currency' => 0])->save();

        $user   = $this->customer();
        $member = $this->enrol($user, 100);

        // Quoted for 500, holds 100.
        $order = $this->order($user, 5.00, $this->redemption($member, 500));
        $this->paid($order);

        $shortfall = $order->refresh()->meta['loyalty_shortfall'] ?? null;

        $this->assertNotNull($shortfall, 'An unfunded quote must leave a mark on the order.');
        $this->assertSame(500, $shortfall['quoted']);
        $this->assertSame(100, $shortfall['charged']);
        $this->assertSame(400, $shortfall['shortfall']);
    }

    // ------------------------------------------------------------------
    // LP-02 — redeeming N points charges exactly N
    // ------------------------------------------------------------------

    /**
     * The exact values the float round-trip got wrong.
     *
     * `floor(round($p * $rate, 2) / $rate)` returned one less than `$p` for 125 of the first
     * 1,000 point values at a rate of 0.01 and 348 at 0.05. These are the first few of each,
     * measured on the same PHP build that serves the site.
     */
    public static function pointValues(): array
    {
        return [
            'rate 0.01, 29 points'  => [0.01, 29],
            'rate 0.01, 47 points'  => [0.01, 47],
            'rate 0.01, 57 points'  => [0.01, 57],
            'rate 0.01, 113 points' => [0.01, 113],
            'rate 0.05, 3 points'   => [0.05, 3],
            'rate 0.05, 6 points'   => [0.05, 6],
            'rate 0.05, 12 points'  => [0.05, 12],
            'rate 0.10, 7 points'   => [0.10, 7],
        ];
    }

    #[Test]
    #[\PHPUnit\Framework\Attributes\DataProvider('pointValues')]
    public function redeeming_a_number_of_points_charges_exactly_that_number(float $rate, int $points): void
    {
        LoyaltySetting::current()->fill(['redeem_value' => $rate])->save();

        $user   = $this->customer();
        $member = $this->enrol($user, 100000);

        // An order large enough that nothing clamps but the request itself.
        $event = $this->checkout($user, $points, subtotal: 10000.00);

        $this->assertCount(1, $event->adjustments, 'The redemption must be offered, not refused.');
        $this->assertSame(
            $points,
            $event->adjustments[0]['meta']['points'],
            'Charging fewer points than were asked for is the float round-trip defect.'
        );
        $this->assertEqualsWithDelta(
            round($points * $rate, 2),
            $event->adjustments[0]['amount'],
            0.0001,
            'The money taken off must match the points charged.'
        );
    }

    #[Test]
    public function meeting_the_minimum_exactly_is_not_refused(): void
    {
        // The cruellest form of the float defect: the recovered figure fell below the
        // minimum, so a customer who met it precisely had the whole redemption refused.
        LoyaltySetting::current()->fill(['redeem_value' => 0.05, 'minimum_redemption' => 3])->save();

        $user = $this->customer();
        $this->enrol($user, 3);

        $event = $this->checkout($user, 3, subtotal: 100.00);

        $this->assertCount(1, $event->adjustments);
        $this->assertSame(3, $event->adjustments[0]['meta']['points']);
    }

    // ------------------------------------------------------------------
    // LP-03 — awarded once, settled by the database
    // ------------------------------------------------------------------

    #[Test]
    public function the_same_paid_event_delivered_twice_awards_once(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 300);

        $event = new OrderStatusChanged($order, Order::FIELD_PAYMENT, 'pending', Order::PAYMENT_PAID);

        // Two deliveries of one payment, which core's own docblock calls ordinary traffic:
        // a gateway webhook and an operator saving the order clear a read-based guard
        // together. Only a constraint can settle it.
        app(AwardPointsOnPaidOrder::class)->onOrderStatusChanged($event);
        app(AwardPointsOnPaidOrder::class)->onOrderStatusChanged($event);

        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)
                ->where('type', LoyaltyTransaction::TYPE_EARN)->count()
        );
        $this->assertSame(300, (int) $this->memberFor($user)->balance);
    }

    // ------------------------------------------------------------------
    // LP-05 — every way a payment ends
    // ------------------------------------------------------------------

    #[Test]
    public function a_voided_payment_takes_the_points_back(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 300);
        $this->paid($order);

        $this->assertSame(300, (int) $this->memberFor($user)->balance);

        app(ReversePointsOnRefundedOrder::class)->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_PAYMENT, Order::PAYMENT_PAID, Order::PAYMENT_VOIDED)
        );

        $this->assertSame(0, (int) $this->memberFor($user)->refresh()->balance);
    }

    #[Test]
    public function cancelling_a_paid_order_takes_the_points_back(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 300);
        $this->paid($order);

        app(ReversePointsOnRefundedOrder::class)->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_STATUS, Order::STATUS_PROCESSING, Order::STATUS_CANCELLED)
        );

        $this->assertSame(0, (int) $this->memberFor($user)->refresh()->balance);
    }

    #[Test]
    public function cancelling_an_unpaid_order_takes_nothing_back(): void
    {
        // Nothing was taken, so there is nothing to give back — and an order can be cancelled
        // on the status axis without any money ever having moved.
        $user  = $this->customer();
        $order = $this->order($user, 300);
        $this->paid($order);
        $order->forceFill(['payment_status' => Order::PAYMENT_UNPAID])->save();

        app(ReversePointsOnRefundedOrder::class)->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_STATUS, Order::STATUS_PROCESSING, Order::STATUS_CANCELLED)
        );

        $this->assertSame(300, (int) $this->memberFor($user)->refresh()->balance);
    }

    // ------------------------------------------------------------------
    // LP-16 — what the programme pays out on
    // ------------------------------------------------------------------

    #[Test]
    public function the_merchandise_base_excludes_tax_and_shipping(): void
    {
        LoyaltySetting::current()->fill(['earn_base' => LoyaltySetting::BASE_MERCHANDISE])->save();

        $user  = $this->customer();
        $order = $this->order($user, 100);
        $order->forceFill([
            'subtotal'       => 100,
            'discount_total' => 20,
            'shipping_total' => 15,
            'tax_total'      => 9,
            'grand_total'    => 104,
        ])->save();

        $this->paid($order);

        // 100 goods less 20 discount. Not 104, which includes tax the shop remits and
        // shipping it largely pays to a carrier.
        $this->assertSame(80, (int) $this->memberFor($user)->balance);
    }

    #[Test]
    public function the_redemption_cap_limits_how_much_of_an_order_points_may_cover(): void
    {
        LoyaltySetting::current()->fill(['max_redemption_percent' => 50])->save();

        $user = $this->customer();
        $this->enrol($user, 100000);

        // 100.00 order, 50% cap, 0.01 a point → at most 5,000 points may be spent.
        $event = $this->checkout($user, 100000, subtotal: 100.00);

        $this->assertCount(1, $event->adjustments);
        $this->assertSame(5000, $event->adjustments[0]['meta']['points']);
        $this->assertEqualsWithDelta(50.00, $event->adjustments[0]['amount'], 0.0001);
    }

    // ------------------------------------------------------------------
    // LP-11 — the ledger is append-only
    // ------------------------------------------------------------------

    #[Test]
    public function a_ledger_entry_cannot_be_deleted(): void
    {
        $user   = $this->customer();
        $member = $this->enrol($user, 100);
        $entry  = LoyaltyTransaction::where('member_id', $member->id)->firstOrFail();

        $this->expectException(\RuntimeException::class);

        app(LoyaltyTransactionRepository::class)->delete($entry->id);
    }

    #[Test]
    public function editing_an_entry_changes_the_reason_and_nothing_that_moves_a_balance(): void
    {
        $user   = $this->customer();
        $member = $this->enrol($user, 100);
        $entry  = LoyaltyTransaction::where('member_id', $member->id)->firstOrFail();

        app(LoyaltyTransactionRepository::class)->update($entry->id, [
            'reason'    => 'Corrected wording',
            'points'    => 999999,
            'type'      => LoyaltyTransaction::TYPE_REDEEM,
            'member_id' => 999999,
        ]);

        $entry->refresh();

        $this->assertSame('Corrected wording', $entry->reason);
        $this->assertSame(100, (int) $entry->points, 'A points change would rewrite a balance.');
        $this->assertSame(LoyaltyTransaction::TYPE_EARN, $entry->type);
        $this->assertSame($member->id, $entry->member_id);
    }

    // ------------------------------------------------------------------
    // LP-10 — entries are attributable
    // ------------------------------------------------------------------

    #[Test]
    public function an_operator_entry_records_its_author_and_a_system_entry_does_not(): void
    {
        $admin  = User::factory()->create(['status' => 'active']);
        $user   = $this->customer();
        $member = $this->enrol($user, 0);

        $this->actingAs($admin);

        $manual = app(LoyaltyTransactionRepository::class)->create([
            'member_id' => $member->id,
            'type'      => LoyaltyTransaction::TYPE_ADJUST,
            'points'    => 50,
            'reason'    => 'Goodwill',
        ]);

        $this->assertSame($admin->id, $manual->created_by);

        // A listener-written entry names nobody: `order_id` already says where it came from,
        // and during a storefront checkout the authenticated user is the customer.
        $order = $this->order($user, 300);
        $this->paid($order);

        $this->assertNull(
            LoyaltyTransaction::where('order_id', $order->id)->where('type', LoyaltyTransaction::TYPE_EARN)->value('created_by')
        );
    }

    // ------------------------------------------------------------------
    // LP-17 — settings are validated, not clamped
    // ------------------------------------------------------------------

    #[Test]
    public function a_non_numeric_earn_rate_is_refused_rather_than_silently_becoming_zero(): void
    {
        $this->expectException(ValidationException::class);

        app(LoyaltyMemberRepository::class)->savePageData('settings', [
            'points_per_currency' => 'not a number',
            'earn_base'           => LoyaltySetting::BASE_GRAND_TOTAL,
            'redeem_value'        => 0.01,
            'minimum_redemption'  => 0,
        ]);
    }

    #[Test]
    public function a_rate_too_large_for_its_column_is_a_field_error_rather_than_a_500(): void
    {
        $this->expectException(ValidationException::class);

        app(LoyaltyMemberRepository::class)->savePageData('settings', [
            'points_per_currency' => 100000000,
            'earn_base'           => LoyaltySetting::BASE_GRAND_TOTAL,
            'redeem_value'        => 0.01,
            'minimum_redemption'  => 0,
        ]);
    }

    #[Test]
    public function a_valid_settings_save_still_writes(): void
    {
        app(LoyaltyMemberRepository::class)->savePageData('settings', [
            'points_per_currency'    => 2.5,
            'earn_base'              => LoyaltySetting::BASE_MERCHANDISE,
            'redeem_value'           => 0.02,
            'minimum_redemption'     => 100,
            'expiry_months'          => '',
            'max_redemption_percent' => 40,
        ]);

        $settings = LoyaltySetting::current()->refresh();

        $this->assertSame('2.50', (string) $settings->points_per_currency);
        $this->assertSame(LoyaltySetting::BASE_MERCHANDISE, $settings->earn_base);
        $this->assertSame(100, $settings->minimum_redemption);
        $this->assertNull($settings->expiry_months, 'A cleared number field means "no policy", not zero.');
        $this->assertSame(40, $settings->max_redemption_percent);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function customer(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    private function enrol(User $user, int $points): LoyaltyMember
    {
        $member = LoyaltyMember::create([
            'user_id'   => $user->id,
            'status'    => 'active',
            'joined_at' => now(),
        ]);

        if ($points > 0) {
            LoyaltyTransaction::create([
                'member_id' => $member->id,
                'points'    => $points,
                'type'      => LoyaltyTransaction::TYPE_EARN,
                'reason'    => 'Opening balance',
            ]);
        }

        return $member->recalculate()->refresh();
    }

    private function order(?User $user, float $total = 100, array $meta = []): Order
    {
        return Order::create([
            'order_number'   => 'LOY-' . uniqid(),
            'user_id'        => $user?->id,
            'status'         => Order::STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_PAID,
            'grand_total'    => $total,
            'subtotal'       => $total,
            'currency'       => 'MYR',
            'meta'           => $meta,
        ]);
    }

    private function paid(Order $order): void
    {
        $event = new OrderStatusChanged($order, Order::FIELD_PAYMENT, 'pending', Order::PAYMENT_PAID);

        app(AwardPointsOnPaidOrder::class)->onOrderStatusChanged($event);
        app(SpendPointsOnPaidOrder::class)->onOrderStatusChanged($event);
    }

    private function memberFor(User $user): ?LoyaltyMember
    {
        return LoyaltyMember::where('user_id', $user->id)->first();
    }

    /** The adjustment shape `CheckoutController` writes into `order.meta`. */
    private function redemption(LoyaltyMember $member, int $points): array
    {
        return ['adjustments' => [[
            'code'   => 'loyalty-points',
            'label'  => $points . ' loyalty points',
            'amount' => $points * 0.01,
            'meta'   => ['member_id' => $member->id, 'points' => $points],
        ]]];
    }

    /** Run the checkout listener the way core does and hand back what it proposed. */
    private function checkout(User $user, int $points, float $subtotal): CheckoutAdjusting
    {
        $event = new CheckoutAdjusting(
            cart: [],
            subtotal: $subtotal,
            discountTotal: 0.0,
            customer: $user,
            input: ['plugin_fields' => ['loyalty_points' => $points]],
        );

        app(RedeemPointsAtCheckout::class)->onCheckoutAdjusting($event);

        return $event;
    }
}

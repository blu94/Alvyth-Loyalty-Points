<?php

namespace Plugin\LoyaltyPoints\Tests;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use PHPUnit\Framework\Attributes\Test;
use Plugin\LoyaltyPoints\Backend\Listeners\AwardPointsOnPaidOrder;
use Plugin\LoyaltyPoints\Backend\Listeners\ReversePointsOnRefundedOrder;
use Plugin\LoyaltyPoints\Backend\Listeners\SpendPointsOnPaidOrder;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Tests\TestCase;

/**
 * The three listeners that move points when an order's payment status changes.
 *
 * This is the half of the programme that runs without anybody pressing anything, so a fault
 * here is invisible until a customer complains. It is also where the worst bug this package
 * has had lived: a refund used to *raise* lifetime points and promote the customer a tier.
 *
 * All three subscribe to one `OrderStatusChanged` and filter on `field` and `to` — Ovynt keeps
 * a single status event rather than an `OrderPaid`, so getting that filter wrong means firing
 * on fulfilment or order-status changes as well.
 */
class OrderListenersTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        LoyaltySetting::current()->fill([
            'points_per_currency' => 1,
            'redeem_value'        => 0.01,
            'minimum_redemption'  => 0,
        ])->save();
    }

    private function customer(): User
    {
        return User::factory()->create(['status' => 'active']);
    }

    /** Core ships no OrderFactory, so orders are built the way core's own tests build them. */
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

        (new AwardPointsOnPaidOrder())->onOrderStatusChanged($event);
        (new SpendPointsOnPaidOrder())->onOrderStatusChanged($event);
    }

    private function refunded(Order $order): void
    {
        (new ReversePointsOnRefundedOrder())->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_PAYMENT, Order::PAYMENT_PAID, Order::PAYMENT_REFUNDED)
        );
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

    // -----------------------------------------------------------------------
    // Awarding
    // -----------------------------------------------------------------------

    #[Test]
    public function a_paid_order_enrols_the_customer_and_awards_points(): void
    {
        // Enrolment on first paid order is deliberate: a programme needing a separate manual
        // step earns nobody anything, which looks identical to being broken.
        $user = $this->customer();
        $this->paid($this->order($user, 240));

        $member = $this->memberFor($user);

        $this->assertNotNull($member, 'A paid order should enrol the customer.');
        $this->assertSame(240, $member->balance);
        $this->assertSame(240, $member->lifetime_points);
    }

    #[Test]
    public function points_come_off_the_grand_total_not_the_subtotal(): void
    {
        // The amount the customer actually handed over — discounts already off, tax and
        // shipping in. Paying out on the subtotal rewards money the shop never took.
        $user = $this->customer();
        $this->paid($this->order($user, 87.60));

        $this->assertSame(87, $this->memberFor($user)->balance, 'Fractions round down, never up.');
    }

    #[Test]
    public function a_guest_order_awards_nothing(): void
    {
        $before = LoyaltyTransaction::count();

        $this->paid($this->order(null, 100));

        $this->assertSame($before, LoyaltyTransaction::count());
    }

    #[Test]
    public function nothing_happens_on_a_status_change_that_is_not_payment(): void
    {
        // One event carries every axis. A listener that ignores `field` would fire on
        // fulfilment changes too and credit an order twice.
        $user  = $this->customer();
        $order = $this->order($user, 100);

        (new AwardPointsOnPaidOrder())->onOrderStatusChanged(
            new OrderStatusChanged($order, Order::FIELD_FULFILLMENT, null, 'paid')
        );

        $this->assertNull($this->memberFor($user));
    }

    #[Test]
    public function paying_the_same_order_twice_awards_once(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 100);

        $this->paid($order);
        $this->paid($order);

        $this->assertSame(100, $this->memberFor($user)->balance);
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN)->count());
    }

    #[Test]
    public function a_suspended_member_stops_accruing(): void
    {
        $user = $this->customer();
        $this->paid($this->order($user, 100));

        $member = $this->memberFor($user);
        $member->update(['status' => 'suspended']);

        $this->paid($this->order($user, 500));

        $this->assertSame(100, $member->refresh()->balance, 'Suspension must be enforced where points are issued.');
    }

    #[Test]
    public function an_earn_rate_of_zero_awards_nothing(): void
    {
        LoyaltySetting::current()->fill(['points_per_currency' => 0])->save();
        $user = $this->customer();

        $this->paid($this->order($user, 100));

        $this->assertNull($this->memberFor($user));
    }

    // -----------------------------------------------------------------------
    // Spending
    // -----------------------------------------------------------------------

    #[Test]
    public function points_promised_at_checkout_are_deducted_when_the_order_is_paid(): void
    {
        $user = $this->customer();
        $this->paid($this->order($user, 1000));
        $member = $this->memberFor($user);
        $this->assertSame(1000, $member->balance);

        $this->paid($this->order($user, 200, $this->redemption($member, 300)));

        // 1000 earned, then 200 earned on the second order, less the 300 redeemed.
        $this->assertSame(900, $member->refresh()->balance);
        $this->assertSame(1200, $member->lifetime_points, 'Spending must not touch standing.');
    }

    #[Test]
    public function a_redemption_is_stored_as_a_negative_entry(): void
    {
        // The sign is applied by the listener, because `signedPoints()` runs in the
        // repository — writing through `create()` stores exactly what it is given, so a
        // positive number against `redeem` would raise the balance it meant to lower.
        $user = $this->customer();
        $this->paid($this->order($user, 1000));
        $member = $this->memberFor($user);

        $order = $this->order($user, 50, $this->redemption($member, 400));
        $this->paid($order);

        $entry = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', LoyaltyTransaction::TYPE_REDEEM)->first();

        $this->assertNotNull($entry);
        $this->assertSame(-400, $entry->points);
    }

    #[Test]
    public function an_order_with_no_redemption_deducts_nothing(): void
    {
        $user = $this->customer();
        $this->paid($this->order($user, 100));

        $this->assertSame(0, LoyaltyTransaction::where('type', LoyaltyTransaction::TYPE_REDEEM)->count());
    }

    // -----------------------------------------------------------------------
    // Refunding
    // -----------------------------------------------------------------------

    #[Test]
    public function a_refund_takes_back_the_points_the_order_earned(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 500);
        $this->paid($order);
        $member = $this->memberFor($user);
        $this->assertSame(500, $member->balance);

        $this->refunded($order);

        $this->assertSame(0, $member->refresh()->balance);
        $this->assertSame(0, $member->lifetime_points, 'A purchase that was undone earned nothing.');
    }

    #[Test]
    public function a_refund_returns_spent_points_without_promoting_anyone(): void
    {
        // The worst bug this package has had. Reversing a redemption writes a POSITIVE mirror;
        // counting it as earning let a refund push a customer up a tier.
        $user = $this->customer();
        $this->paid($this->order($user, 1200));
        $member = $this->memberFor($user);

        $order = $this->order($user, 240, $this->redemption($member, 1000));
        $this->paid($order);
        $this->assertSame([440, 1440], [$member->refresh()->balance, $member->lifetime_points]);

        $this->refunded($order);
        $member->refresh();

        $this->assertSame(1200, $member->balance);
        $this->assertSame(1200, $member->lifetime_points);
        $this->assertNotSame(2440, $member->lifetime_points);
    }

    #[Test]
    public function every_reversal_points_at_what_it_undoes(): void
    {
        // `reverses_id`, not a reason string: tier standing must not depend on prose that a
        // translation or an edit could change.
        $user  = $this->customer();
        $order = $this->order($user, 300);
        $this->paid($order);

        $this->refunded($order);

        $reversals = LoyaltyTransaction::where('order_id', $order->id)->whereNotNull('reverses_id')->get();

        $this->assertCount(1, $reversals);
        $this->assertNotNull($reversals->first()->reverses_id);
    }

    #[Test]
    public function refunding_twice_reverses_once(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 300);
        $this->paid($order);

        $this->refunded($order);
        $this->refunded($order);

        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)
            ->whereNotNull('reverses_id')->count());
        $this->assertSame(0, $this->memberFor($user)->refresh()->balance);
    }

    #[Test]
    public function refunding_an_order_that_earned_nothing_does_nothing(): void
    {
        $user  = $this->customer();
        $order = $this->order($user, 300);

        $this->refunded($order);

        $this->assertNull($this->memberFor($user));
    }
}

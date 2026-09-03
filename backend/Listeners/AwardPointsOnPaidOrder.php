<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;

/**
 * Award points when an order is actually paid.
 *
 * **On payment, not on placement.** `OrderPlaced` fires the moment an order exists, including
 * one created unpaid in admin or abandoned at a payment gateway. Points issued there are a
 * liability against money that never arrived, and nothing takes them back. `OrderStatusChanged`
 * carries which axis moved, so this filters for `payment_status → paid`.
 *
 * Ovynt keeps one status event rather than an `OrderPaid`, precisely so the three workflows do
 * not each grow their own vocabulary — the filter below is the intended way to consume it.
 *
 * **Not `ShouldQueue`, unlike the core listeners.** Those send email, which is slow and may
 * fail, so deferring is right. This writes two rows and must not be lost: a host that sets
 * `QUEUE_CONNECTION=database` without running a worker would silently stop awarding points,
 * and nobody would notice until a customer asked where theirs went. Dispatch is already wrapped
 * in `dispatchQuietly`, so throwing here cannot roll back the order.
 *
 * **Awarded once per order by constraint, not by a preceding read.** The old guard read
 * `where(order_id)->where(type)->exists()` and then inserted; two deliveries of the same paid
 * transition cleared that read together and both wrote. The `(order_id, type)` uniqueness
 * guard settles it in the database, and {@see Ledger} absorbs the loser as "already handled".
 */
class AwardPointsOnPaidOrder
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        if ($event->field !== Order::FIELD_PAYMENT || $event->to !== 'paid') {
            return;
        }

        $order = $event->order;

        // Guest checkout has no user to credit. Not an error — most shops allow it.
        if ($order->user_id === null) {
            return;
        }

        try {
            $this->award($order);
        } catch (\Throwable $e) {
            // The sale is what matters. A failure here is logged and swallowed rather than
            // propagated, because the alternative is a paid order whose points calculation
            // took the checkout down with it.
            Log::error('Loyalty points could not be awarded for a paid order', [
                'order' => $order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function award(Order $order): void
    {
        $settings = LoyaltySetting::current();
        $rate     = (float) $settings->points_per_currency;

        if ($rate <= 0) {
            return;
        }

        $points = (int) floor($this->earnBase($order, $settings) * $rate);

        if ($points < 1) {
            return;
        }

        $member = $this->enrol($order);

        // A suspended member keeps their balance but stops accruing — that is what suspension
        // is for, and it has to be enforced where points are issued rather than only in the UI.
        if ($member === null || $member->status !== 'active') {
            return;
        }

        $this->ledger->post([
            'member_id' => $member->id,
            'points'    => $points,
            'type'      => LoyaltyTransaction::TYPE_EARN,
            'reason'    => 'Order ' . $order->order_number,
            'order_id'  => $order->id,
        ]);
    }

    /**
     * The amount this order earns points on.
     *
     * `merchandise` is the goods, net of every discount — including the customer's own
     * redemption, which core folds into `discount_total`. It excludes tax, which the shop
     * collects and remits rather than keeps, and shipping, which it largely passes to a
     * carrier; paying points out on either is paying out on money the shop never had.
     *
     * `grand_total` is what the customer actually handed over, and remains the default so
     * that upgrading this package does not silently change what an existing programme costs.
     */
    private function earnBase(Order $order, LoyaltySetting $settings): float
    {
        if ($settings->earn_base === LoyaltySetting::BASE_MERCHANDISE) {
            return max(0, (float) $order->subtotal - (float) $order->discount_total);
        }

        return max(0, (float) $order->grand_total);
    }

    /**
     * Find or create this customer's membership.
     *
     * Enrol on first paid order. A programme that requires a separate manual enrolment step
     * silently earns nobody anything, which is indistinguishable from being broken.
     *
     * `firstOrCreate` is itself a read-then-write, and `user_id` is unique: two paid orders
     * for a new customer arriving together meant one insert won and the other threw, where
     * the surrounding catch turned a customer's points into a log line nobody reads. The
     * loser re-reads instead, which is what it wanted in the first place.
     */
    private function enrol(Order $order): ?LoyaltyMember
    {
        try {
            return LoyaltyMember::firstOrCreate(
                ['user_id' => $order->user_id],
                ['status' => 'active', 'joined_at' => now()]
            );
        } catch (QueryException $e) {
            return LoyaltyMember::where('user_id', $order->user_id)->first();
        }
    }
}

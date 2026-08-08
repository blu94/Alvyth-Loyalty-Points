<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

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
 */
class AwardPointsOnPaidOrder
{
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
        // One entry per order, whatever happens to the payment axis afterwards. An order that
        // goes paid → refunded → paid would otherwise be credited twice, and `order_id` is
        // indexed precisely so this check is cheap.
        $already = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', LoyaltyTransaction::TYPE_EARN)
            ->exists();

        if ($already) {
            return;
        }

        $settings = LoyaltySetting::current();
        $rate     = (float) $settings->points_per_currency;

        if ($rate <= 0) {
            return;
        }

        // Points come off `grand_total`, the amount the customer actually paid — tax and
        // shipping included, discounts already deducted. Rewarding the subtotal would pay out
        // on money the shop never took.
        $points = (int) floor((float) $order->grand_total * $rate);

        if ($points < 1) {
            return;
        }

        // Enrol on first paid order. A programme that requires a separate manual enrolment
        // step silently earns nobody anything, which is indistinguishable from being broken.
        $member = LoyaltyMember::firstOrCreate(
            ['user_id' => $order->user_id],
            ['status' => 'active', 'joined_at' => now()]
        );

        // A suspended member keeps their balance but stops accruing — that is what suspension
        // is for, and it has to be enforced where points are issued rather than only in the UI.
        if ($member->status !== 'active') {
            return;
        }

        LoyaltyTransaction::create([
            'member_id' => $member->id,
            'points'    => $points,
            'type'      => LoyaltyTransaction::TYPE_EARN,
            'reason'    => 'Order ' . $order->order_number,
            'order_id'  => $order->id,
        ]);

        $member->recalculate();
    }
}

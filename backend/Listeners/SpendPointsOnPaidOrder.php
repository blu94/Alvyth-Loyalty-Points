<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * Take the points off the ledger once the order is actually paid.
 *
 * Deliberately separate from the checkout listener that priced the reduction. Pricing happens
 * while a payment may still fail, be abandoned at a gateway, or never be attempted; debiting
 * there would mean inventing a refund path for the ordinary case rather than the rare one.
 * The customer is charged the reduced amount and the points leave their balance at the same
 * moment — when money changes hands.
 *
 * How many points were spent is read from the order's own `meta.adjustments`, written by core
 * at checkout. Recomputing it from the current balance and rate would give a different answer
 * the moment either changed between placing and paying.
 *
 * Runs alongside {@see AwardPointsOnPaidOrder} on the same event: one order can both spend
 * points and earn them on what was actually paid. Neither is aware of the other, and the
 * ledger is the only thing that reconciles them — which is the whole reason balances are
 * recomputed from it rather than incremented.
 */
class SpendPointsOnPaidOrder
{
    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        if ($event->field !== Order::FIELD_PAYMENT || $event->to !== 'paid') {
            return;
        }

        try {
            $this->spend($event->order);
        } catch (\Throwable $e) {
            Log::error('Loyalty points could not be deducted for a paid order', [
                'order' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function spend(Order $order): void
    {
        $adjustment = collect($order->meta['adjustments'] ?? [])
            ->firstWhere('code', 'loyalty-points');

        $points = (int) ($adjustment['meta']['points'] ?? 0);

        if ($points < 1) {
            return;
        }

        // One redemption per order, whatever the payment axis does afterwards. `order_id` is
        // indexed for exactly this check, and paid → refunded → paid must not charge twice.
        $already = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', LoyaltyTransaction::TYPE_REDEEM)
            ->exists();

        if ($already) {
            return;
        }

        $member = LoyaltyMember::find($adjustment['meta']['member_id'] ?? null);

        if ($member === null) {
            return;
        }

        // The sign has to be applied here. `signedPoints()` is called by the *repository*,
        // not by the model, so writing through `create()` stores exactly what it is given —
        // a positive number against a `redeem` would have raised the balance it was meant to
        // lower. Asked of the type rather than written as `-$points`, so the rule stays in
        // one place and an `adjust` entry keeps the sign its author intended.
        LoyaltyTransaction::create([
            'member_id' => $member->id,
            'points'    => LoyaltyTransaction::signedPoints(LoyaltyTransaction::TYPE_REDEEM, $points),
            'type'      => LoyaltyTransaction::TYPE_REDEEM,
            'reason'    => 'Redeemed on order ' . $order->order_number,
            'order_id'  => $order->id,
        ]);

        $member->recalculate();
    }
}

<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * Undo the points side of an order when the money goes back.
 *
 * Without this a refund is silently one-directional: the customer keeps points they were
 * awarded for a purchase that no longer stands, and — worse — points they *spent* stay spent
 * on an order they no longer have. The second is the shop taking something and giving nothing
 * back, which is not a rounding error, it is a small theft that nobody notices until someone
 * complains.
 *
 * **Reversed with new entries, never by deleting the originals.** The ledger is an audit
 * record: what happened, happened. Deleting the earn entry would make the order's history
 * claim points were never issued, and the reason a balance is recomputed from `SUM(points)`
 * rather than incremented is precisely so corrections can be additive and still be exact.
 *
 * **`refunded` only, not `partially_refunded`.** A partial refund has no defensible split
 * without knowing which lines went back and what the merchant intends — refunding 40% of an
 * order does not obviously mean clawing back 40% of the points, and guessing would take
 * points from customers on a rule nobody agreed to. Left for an operator to adjust by hand,
 * which the Points Activity screen exists for.
 */
class ReversePointsOnRefundedOrder
{
    /** Human-readable only. What a reversal *is* is recorded by `reverses_id`. */
    private const REASON_PREFIX = 'Reversed on refund of order ';

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        if ($event->field !== Order::FIELD_PAYMENT || $event->to !== Order::PAYMENT_REFUNDED) {
            return;
        }

        try {
            $this->reverse($event->order);
        } catch (\Throwable $e) {
            Log::error('Loyalty points could not be reversed for a refunded order', [
                'order' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function reverse(Order $order): void
    {
        $entries = LoyaltyTransaction::where('order_id', $order->id)->get();

        if ($entries->isEmpty()) {
            return;
        }

        // Idempotent on the same terms as awarding: refunded → paid → refunded must not
        // reverse twice, and a reversal must never be reversed. Asked of the pointer rather
        // than the reason text — the same fact `recalculate()` reads, so the two cannot
        // disagree about which entries are corrections.
        if ($entries->contains(fn ($e) => $e->reverses_id !== null)) {
            return;
        }

        $originals = $entries->whereNull('reverses_id');

        $memberIds = [];

        foreach ($originals as $entry) {
            // `adjust` keeps the sign it is given, which is what makes it the right type for
            // a correction: the mirror of an earn is negative, the mirror of a redemption is
            // positive, and one type expresses both without a second code path.
            LoyaltyTransaction::create([
                'member_id' => $entry->member_id,
                'points'    => -$entry->points,
                'type'      => LoyaltyTransaction::TYPE_ADJUST,
                'reason'    => self::REASON_PREFIX . $order->order_number,
                'order_id'  => $order->id,

                // The fact that makes this a correction rather than a grant. Reversing an
                // earn must lower lifetime standing; reversing a redemption must return
                // spendable balance and leave standing alone. Only the original says which.
                'reverses_id' => $entry->id,
            ]);

            $memberIds[$entry->member_id] = true;
        }

        // Recomputed from the ledger, so the balance is whatever the entries now say —
        // including the ones just written. Tier standing follows, and because reversing an
        // earn lowers `lifetime_points`, a customer refunded down past a threshold loses the
        // standing that purchase bought. That is correct: the purchase did not happen.
        foreach (array_keys($memberIds) as $memberId) {
            LoyaltyMember::find($memberId)?->recalculate();
        }
    }
}

<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Contracts\Notification\Notifier;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;

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
 * ## Every way a payment ends, not only the one called "refunded"
 *
 * This used to fire on `payment_status → refunded` alone. `voided` is an equally terminal
 * unwind — the gateway reversed the authorisation and no money was taken — and an order
 * `cancelled` after payment is the same event on the other status axis. Both left the earned
 * points standing and the spent points spent, which is the exact harm the paragraph above
 * describes; it simply stopped one status short of covering them.
 *
 * **A partial refund is reported, not guessed.** There is still no defensible split without
 * knowing which lines went back — refunding 40% of an order does not obviously mean clawing
 * back 40% of the points, and guessing would take points from customers on a rule nobody
 * agreed to. What changed is that it no longer happens in silence: staff are told, with the
 * order and the points at stake named, so the manual correction the Points Activity screen
 * exists for is one somebody knows to make. An unreported liability is worse than an
 * approximate one.
 */
class ReversePointsOnRefundedOrder
{
    /** Human-readable only. What a reversal *is* is recorded by `reverses_id`. */
    private const REASON_PREFIX = 'Reversed on refund of order ';

    /** The payment outcomes that end an order's money for good. */
    private const PAYMENT_UNWINDS = [
        Order::PAYMENT_REFUNDED,
        Order::PAYMENT_VOIDED,
    ];

    public function __construct(private Ledger $ledger)
    {
    }

    public function onOrderStatusChanged(OrderStatusChanged $event): void
    {
        try {
            if ($this->isPartialRefund($event)) {
                $this->reportPartial($event->order);

                return;
            }

            if (! $this->isUnwind($event)) {
                return;
            }

            $this->reverse($event->order);
        } catch (\Throwable $e) {
            Log::error('Loyalty points could not be reversed for a refunded order', [
                'order' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Whether this transition ends the order's money.
     *
     * Two axes, because core keeps three orthogonal workflows and an order can die on either
     * of them. On the payment axis, refunded and voided are both terminal. On the status axis,
     * a cancellation only implies an unwind when money had actually been taken — cancelling an
     * unpaid order takes nothing back and there is nothing to reverse.
     */
    private function isUnwind(OrderStatusChanged $event): bool
    {
        if ($event->field === Order::FIELD_PAYMENT) {
            return in_array($event->to, self::PAYMENT_UNWINDS, true);
        }

        return $event->field === Order::FIELD_STATUS
            && $event->to === Order::STATUS_CANCELLED
            && $event->order->payment_status === Order::PAYMENT_PAID;
    }

    private function isPartialRefund(OrderStatusChanged $event): bool
    {
        return $event->field === Order::FIELD_PAYMENT
            && $event->to === Order::PAYMENT_PARTIALLY_REFUNDED;
    }

    private function reverse(Order $order): void
    {
        $originals = LoyaltyTransaction::where('order_id', $order->id)
            ->whereNull('reverses_id')
            ->get();

        if ($originals->isEmpty()) {
            return;
        }

        // Idempotent on the same terms as awarding: refunded → paid → refunded must not
        // reverse twice, and a reversal must never be reversed.
        //
        // Asked as "has anything already undone these entries?" rather than by scanning the
        // order's own rows for a correction, because a mirror no longer carries the order id
        // — see the write below. `reverses_id` is the same fact `recalculate()` reads, so the
        // two cannot disagree about which entries are corrections, and this asks it of
        // precisely the entries in question rather than of everything sharing an order.
        $alreadyReversed = LoyaltyTransaction::whereIn('reverses_id', $originals->pluck('id'))->exists();

        if ($alreadyReversed) {
            return;
        }

        foreach ($originals as $entry) {
            // `adjust` keeps the sign it is given, which is what makes it the right type for
            // a correction: the mirror of an earn is negative, the mirror of a redemption is
            // positive, and one type expresses both without a second code path.
            //
            // Posted through the Ledger so each mirror and the balance it produces are one
            // transaction under the member's row lock — the same guarantee the originals got.
            $this->ledger->post([
                'member_id' => $entry->member_id,
                'points'    => -$entry->points,
                'type'      => LoyaltyTransaction::TYPE_ADJUST,
                'reason'    => self::REASON_PREFIX . $order->order_number,

                // Null, not the order's id. The `(order_id, type)` guard allows one `adjust`
                // per order, and an order that both earned and redeemed needs two mirrors —
                // constraining them would silently drop the second. What they undo is already
                // recorded by `reverses_id`, which is the pointer everything else reads.
                'order_id'  => null,

                // The fact that makes this a correction rather than a grant. Reversing an
                // earn must lower lifetime standing; reversing a redemption must return
                // spendable balance and leave standing alone. Only the original says which.
                'reverses_id' => $entry->id,
            ]);
        }
    }

    /**
     * Tell staff a partial refund needs a decision nobody can make automatically.
     *
     * Named with the points at stake so the message is actionable on its own: an operator can
     * see what the order earned and spent without opening the ledger to find out whether the
     * notification is worth acting on.
     */
    private function reportPartial(Order $order): void
    {
        $entries = LoyaltyTransaction::where('order_id', $order->id)->whereNull('reverses_id')->get();

        if ($entries->isEmpty()) {
            return;
        }

        $earned   = (int) $entries->where('type', LoyaltyTransaction::TYPE_EARN)->sum('points');
        $redeemed = abs((int) $entries->where('type', LoyaltyTransaction::TYPE_REDEEM)->sum('points'));

        app(Notifier::class)->toStaff(
            'plugin:loyalty-points.partial_refund_review',
            [
                'order_number' => (string) $order->order_number,
                'earned'       => number_format($earned),
                'redeemed'     => number_format($redeemed),
            ],
            $order,
        );
    }
}

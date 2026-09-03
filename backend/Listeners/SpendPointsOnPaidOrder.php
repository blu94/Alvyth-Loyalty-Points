<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Contracts\Notification\Notifier;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;

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
 * ## The balance is checked here, not only at checkout
 *
 * Quoting and debiting are separated by however long a payment takes, and the balance can
 * move in between. A customer with 500 points could place one order redeeming 500, place a
 * second before paying either, and have both pass the checkout guard; both then paid wrote
 * two −500 entries against a ledger that had only ever credited 500. Expiry, an operator's
 * downward adjustment and a refund reversal open the same gap.
 *
 * So the debit is clamped to what the member actually holds, under the row lock {@see Ledger}
 * takes, and the shortfall is recorded on the order and raised with staff. Somebody has to
 * decide whether to honour a discount the customer was quoted and did not fund; what must not
 * happen is the shop giving away value in silence.
 *
 * **Clamping rather than holding the points at checkout.** The textbook answer is to reserve
 * on quote and convert on capture, and it is the right shape where a reservation can be
 * released. Here it cannot: there is no abandoned-checkout event to release on and a plugin
 * registers no console command, so nothing could sweep the holds a customer never returned to
 * pay. Reservations that only ever accumulate would cost customers more points than
 * over-spending ever did.
 *
 * Runs alongside {@see AwardPointsOnPaidOrder} on the same event: one order can both spend
 * points and earn them on what was actually paid. Neither is aware of the other, and the
 * ledger is the only thing that reconciles them — which is the whole reason balances are
 * recomputed from it rather than incremented.
 */
class SpendPointsOnPaidOrder
{
    public function __construct(private Ledger $ledger)
    {
    }

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

        $quoted = (int) ($adjustment['meta']['points'] ?? 0);

        if ($quoted < 1) {
            return;
        }

        $member = LoyaltyMember::find($adjustment['meta']['member_id'] ?? null);

        if ($member === null) {
            return;
        }

        // What they can actually fund, now, rather than when they were quoted. Never below
        // zero: a member already overdrawn from some earlier fault must not be driven further.
        $spendable = min($quoted, max(0, (int) $member->balance));

        if ($spendable < 1) {
            $this->reportShortfall($order, $member, $quoted, 0);

            return;
        }

        // One redemption per order, whatever the payment axis does afterwards. Settled by the
        // `(order_id, type)` uniqueness guard rather than by a preceding read: two deliveries
        // of the same paid transition both clear a read and only one can clear a constraint.
        // `post()` answers null when the guard fires, which means "already handled".
        $entry = $this->ledger->post([
            'member_id' => $member->id,
            // The sign has to be applied here. `signedPoints()` is called by the *repository*,
            // not by the model, so writing through the Ledger stores exactly what it is given
            // — a positive number against a `redeem` would have raised the balance it was
            // meant to lower. Asked of the type rather than written as `-$spendable`, so the
            // rule stays in one place and an `adjust` entry keeps the sign its author intended.
            'points'    => LoyaltyTransaction::signedPoints(LoyaltyTransaction::TYPE_REDEEM, $spendable),
            'type'      => LoyaltyTransaction::TYPE_REDEEM,
            'reason'    => 'Redeemed on order ' . $order->order_number,
            'order_id'  => $order->id,
        ]);

        if ($entry !== null && $spendable < $quoted) {
            $this->reportShortfall($order, $member, $quoted, $spendable);
        }
    }

    /**
     * Say that the customer was quoted more relief than their balance could fund.
     *
     * Written onto the order as well as raised with staff, because the two answer different
     * questions: the notification is how somebody finds out today, and the note on the order
     * is what is still there when the same order is looked at in six months.
     *
     * The order is priced and paid by this point and is not re-priced here — the customer has
     * been charged. Whether to recover the difference or absorb it is a commercial decision,
     * and a listener is not the place to make it.
     */
    private function reportShortfall(Order $order, LoyaltyMember $member, int $quoted, int $charged): void
    {
        $shortfall = $quoted - $charged;

        Log::warning('Loyalty points: an order was quoted more points than the member could fund', [
            'order'     => $order->id,
            'member'    => $member->id,
            'quoted'    => $quoted,
            'charged'   => $charged,
            'shortfall' => $shortfall,
        ]);

        $meta = $order->meta ?? [];
        $meta['loyalty_shortfall'] = [
            'quoted'     => $quoted,
            'charged'    => $charged,
            'shortfall'  => $shortfall,
            'recorded_at' => now()->toIso8601String(),
        ];
        $order->forceFill(['meta' => $meta])->saveQuietly();

        app(Notifier::class)->toStaff(
            'plugin:loyalty-points.redemption_shortfall',
            [
                'order_number' => (string) $order->order_number,
                'quoted'       => number_format($quoted),
                'charged'      => number_format($charged),
                'shortfall'    => number_format($shortfall),
            ],
            $order,
        );
    }
}

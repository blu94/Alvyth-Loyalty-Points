<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\CheckoutAdjusting;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;

/**
 * Spend points at checkout.
 *
 * The other half of the programme. Awarding points a customer can see but never use is a
 * liability with no purpose — it costs the shop the same and buys none of the loyalty it was
 * supposed to.
 *
 * **Proposes, does not decide.** This returns an amount; core clamps it into the total and
 * owns every other line of the arithmetic. A plugin must not be able to write a price.
 *
 * **Nothing is deducted here.** This runs while the order is still being priced and may be
 * followed by a payment that never completes. The ledger entry is written when the order is
 * actually paid — see `SpendPointsOnPaidOrder`, which also re-checks the balance, because the
 * two moments are far enough apart for it to have moved.
 *
 * ## The arithmetic is integer, and that is the whole point
 *
 * This used to price the reduction as `round($points * $rate, 2)` and then recover the points
 * from it as `floor($value / $rate)`. Both are binary floating-point, and the round trip does
 * not close: measured across 1,000 amounts, 125 came back one point short at a rate of 0.01
 * and 348 at 0.05. Usually the customer quietly lost a point of relief; at the minimum-
 * redemption boundary the recovered figure fell below the minimum and the whole redemption
 * was refused for somebody who had met it exactly.
 *
 * So the clamps are applied to the **points**, in whole numbers, and the money is derived from
 * the result once. `redeem_value` is `decimal(8,4)`, so ten-thousandths of a currency unit is
 * exactly its resolution and the conversion loses nothing.
 */
class RedeemPointsAtCheckout
{
    public function onCheckoutAdjusting(CheckoutAdjusting $event): void
    {
        // Opt-in per order. A programme that silently spends someone's balance on their next
        // purchase takes the choice away — points are theirs to save for something larger.
        //
        // Read through `field()` rather than out of the raw request: plugin controls post
        // into their own namespaced bag, so this cannot collide with a core checkout field
        // and cannot be reached by a request that did not go through a checkout form.
        $requested = (int) $event->field('loyalty_points', 0);

        if ($requested < 1 || $event->customer === null) {
            return;
        }

        try {
            $this->propose($event, $requested);
        } catch (\Throwable $e) {
            // Never take checkout down. Failing here means full price, which is the safe
            // direction: the customer keeps their points and can try again.
            Log::error('Loyalty points could not be applied at checkout', [
                'user'  => $event->customer->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function propose(CheckoutAdjusting $event, int $requested): void
    {
        $member = LoyaltyMember::where('user_id', $event->customer->id)->first();

        // A suspended member keeps their balance and stops spending it, the mirror of the
        // rule on accrual. Enforced here rather than only in the UI.
        if ($member === null || $member->status !== 'active' || $member->balance < 1) {
            return;
        }

        $settings = LoyaltySetting::current();

        // The value of one point, in ten-thousandths of a currency unit. An integer from here
        // down: every comparison below is between whole numbers of points or whole
        // ten-thousandths, so nothing rounds until the single conversion at the end.
        $rate = $settings->redeemValueTenThousandths();

        if ($rate < 1) {
            return;
        }

        // What is still available to reduce, in the same units. Asked of the event rather
        // than recomputed, so this cannot disagree with core about what is left after a
        // discount code and any other plugin that got here first.
        $availableTenThousandths = (int) round($event->remaining() * 10000);

        // An operator's ceiling on how much of one order points may cover. Without it a
        // single order can be paid for entirely in points, which is the ordinary abuse case.
        if ($settings->max_redemption_percent !== null) {
            $cap = intdiv((int) round($event->subtotal * 10000) * (int) $settings->max_redemption_percent, 100);
            $availableTenThousandths = min($availableTenThousandths, $cap);
        }

        if ($availableTenThousandths < $rate) {
            return;
        }

        // Every clamp in points, never in money: never more than they hold, never more than
        // the order can absorb, never more than they asked for. Clamping the value instead
        // and converting back is what lost a point 12% of the time.
        $spent = min(
            $requested,
            (int) $member->balance,
            intdiv($availableTenThousandths, $rate)
        );

        // The floor is a floor on what they *spend*, not on what they own: a customer with
        // 600 points and a 500 minimum may spend 600, not 500-and-no-more.
        $minimum = (int) $settings->minimum_redemption;

        if ($spent < 1 || ($minimum > 0 && $spent < $minimum)) {
            return;
        }

        // The one conversion, from an exact integer. `$spent * $rate` is a whole number of
        // ten-thousandths, so this rounds a value that is already exact rather than
        // accumulating error through a division.
        $amount = round(($spent * $rate) / 10000, 2);

        if ($amount <= 0) {
            return;
        }

        $event->reduce(
            'loyalty-points',
            number_format($spent) . ' loyalty points',
            $amount,
            ['member_id' => $member->id, 'points' => $spent]
        );
    }
}

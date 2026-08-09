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
 * actually paid — see `SpendPointsOnPaidOrder` — so an abandoned checkout costs the customer
 * nothing. The alternative, debiting now and refunding on failure, invents a reversal path
 * for the common case rather than the rare one.
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
        $rate     = (float) $settings->redeem_value;

        if ($rate <= 0) {
            return;
        }

        // Never more than they hold.
        $points = min($requested, (int) $member->balance);

        // The floor is a floor on what they *spend*, not on what they own: a customer with
        // 600 points and a 500 minimum may spend 600, not 500-and-no-more.
        $minimum = (int) $settings->minimum_redemption;

        if ($minimum > 0 && $points < $minimum) {
            return;
        }

        // Never more than the order is worth. Asked of the event rather than recomputed, so
        // this cannot disagree with core about what is left after a discount code and any
        // other plugin that got here first.
        $value = min(round($points * $rate, 2), $event->remaining());

        if ($value <= 0) {
            return;
        }

        // Charge only for the points actually used. Clamping the *value* without clamping
        // the points would bill a customer 600 points for 3.00 of relief on a 3.00 order.
        $spent = (int) floor($value / $rate);

        if ($spent < 1 || ($minimum > 0 && $spent < $minimum)) {
            return;
        }

        $event->reduce(
            'loyalty-points',
            number_format($spent) . ' loyalty points',
            round($spent * $rate, 2),
            ['member_id' => $member->id, 'points' => $spent]
        );
    }
}

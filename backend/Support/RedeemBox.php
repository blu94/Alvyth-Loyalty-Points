<?php

namespace Plugin\LoyaltyPoints\Backend\Support;

use Illuminate\Support\Facades\View;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;

/**
 * The control that lets a customer actually spend their points.
 *
 * The programme was priced and deducted server-side long before this existed:
 * `RedeemPointsAtCheckout` read a number out of the checkout request, and nothing on the
 * storefront could put one there. The balance was visible on the account page and unspendable
 * everywhere — points a customer can see and cannot use are a liability that buys none of the
 * loyalty they were issued for.
 *
 * **The guards here mirror the listener's exactly.** A box offered to somebody the listener
 * will refuse — a suspended member, a balance under the minimum, a shop with redemption
 * switched off — is a promise the checkout breaks. Where they must agree the listener is the
 * authority; this decides only whether to ask the question.
 *
 * **Renders plain HTML with no JavaScript of its own, deliberately.** The region a theme puts
 * this in is usually inside the theme's own Vue app, which compiles whatever it contains as a
 * template: a `createApp` here would never run and a `<style>` block would be stripped
 * silently, as one already was on the account page. The input carries
 * `data-checkout-field="loyalty_points"` and the theme carries the value to the server —
 * that contract exists so a plugin does not need a script to take part in checkout.
 */
class RedeemBox
{
    /**
     * @param  array<string,mixed>  $data  whatever the calling page knew, from the slot
     */
    public function render(array $data = []): string
    {
        $user = CurrentCustomer::resolve();

        if ($user === null) {
            return '';
        }

        $member = LoyaltyMember::where('user_id', $user->id)->first();

        // A suspended member keeps their balance and stops spending it — the same rule the
        // listener enforces, asked here so the control never appears for someone it would
        // then reject.
        if ($member === null || $member->status !== 'active' || $member->balance < 1) {
            return '';
        }

        $settings = LoyaltySetting::current();
        $rate     = (float) $settings->redeem_value;

        // A shop that has set redemption to nothing is not running a redeemable programme;
        // points still accrue and still show on the account page.
        if ($rate <= 0) {
            return '';
        }

        $balance = (int) $member->balance;
        $minimum = (int) $settings->minimum_redemption;

        // Below the minimum there is nothing this control could be used for. The account
        // page is where the rule is explained; checkout is not the place to teach it.
        if ($minimum > 0 && $balance < $minimum) {
            return '';
        }

        return View::make('plugin-loyalty-points::redeem', [
            'data'    => $data,
            'balance' => $balance,
            'minimum' => $minimum,
            'rate'    => $rate,

            // What the whole balance is worth at today's rate. The order may be smaller, in
            // which case core clamps the reduction and charges only the points it used — so
            // this is an honest ceiling rather than a quotation.
            'worth'   => number_format($balance * $rate, 2),
        ])->render();
    }
}

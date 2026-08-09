<?php

namespace Plugin\LoyaltyPoints\Backend\Support;

use Illuminate\Support\Facades\View;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;

/**
 * The customer's own view of their points.
 *
 * Until this existed the programme was invisible to the people in it: an operator could see
 * balances and tiers, and the customer earning them could not. Points nobody can see change
 * nobody's behaviour, so the admin screens were bookkeeping for a loyalty programme that was
 * not yet one.
 *
 * **Rendered on the server, not fetched by the browser.** The obvious shape — an empty div
 * plus a `fetch` — is not open to a plugin: packages cannot register routes, so there is no
 * endpoint for the JS to call and no way to add one from here. Reading the customer during
 * render is what makes this possible at all without a core change.
 */
class PointsBadge
{
    /**
     * @param  array<string,mixed>  $data  the section's content fields
     */
    public function render(array $data, string $locale, string $viewPath): string
    {
        $user = CurrentCustomer::resolve();

        // A guest has no points to show and no reason to be told about someone else's.
        // Rendering nothing is also what a blocked plugin does, so the page looks the same
        // either way and the theme never has to care which happened.
        if ($user === null) {
            return '';
        }

        $member = LoyaltyMember::with('tier')->where('user_id', $user->id)->first();

        if ($member === null) {
            return '';
        }

        $settings = LoyaltySetting::current();

        // The tier above the one they hold, by threshold. Null at the top — a customer who
        // has reached the highest tier is shown their standing, not an empty progress bar
        // toward nothing.
        $nextTier = LoyaltyTier::query()
            ->where('status', 'active')
            ->where('threshold', '>', $member->lifetime_points)
            ->orderBy('threshold')
            ->first();

        return View::make('plugin-loyalty-points::badge', [
            'data'     => $data,
            'locale'   => $locale,
            'member'   => $member,
            'tier'     => $member->tier,
            'nextTier' => $nextTier,

            // Points still needed, never negative. Guarded because `lifetime_points` is
            // recomputed from the ledger and a tier's threshold can be edited downward
            // between the two reads.
            'toNext'   => $nextTier ? max(0, $nextTier->threshold - $member->lifetime_points) : null,

            // What the balance is worth today, at the operator's current redeem rate.
            'value'    => number_format($member->balance * (float) $settings->redeem_value, 2),

            'minimum'  => (int) $settings->minimum_redemption,
        ])->render();
    }
}

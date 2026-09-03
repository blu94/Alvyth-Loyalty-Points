<?php

namespace Plugin\LoyaltyPoints\Backend\Listeners;

use App\Events\GdprCollecting;
use Illuminate\Support\Facades\Log;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;

/**
 * Put the customer's own loyalty record into their subject access response.
 *
 * A membership is a behavioural profile: what somebody has bought, how often, how much they
 * were rewarded for it and where they stand. That is personal data under Article 15, and it
 * was absent from the export a controller hands over — not withheld, simply unreachable,
 * because `GdprService` enumerates core's tables and had no way to ask a package anything.
 *
 * **The whole ledger, not a summary.** Article 15 is a right to the data, and a balance
 * without the entries behind it is a conclusion rather than a record — the customer cannot
 * check it, which is the point of being given it. The entries are also the only place a
 * `reason` an operator typed can be seen, and that free text is the one field here capable of
 * holding personal data nobody planned for.
 *
 * **Nothing is contributed for someone with no membership.** An empty section in a legal
 * document invites the reader to wonder what was withheld.
 *
 * Erasure needs no counterpart. `anonymiseUser()` rewrites the `users` row in place and the
 * membership hangs off `user_id`, so the ledger survives with no name attached to it — which
 * is the correct outcome: the shop's liability is still real and is no longer about an
 * identifiable person.
 */
class ContributeGdprExport
{
    public function onGdprCollecting(GdprCollecting $event): void
    {
        try {
            $this->contribute($event);
        } catch (\Throwable $e) {
            // Never take a compliance export down. The section is missing rather than the
            // file, and the caveat below is what stops that being silent.
            Log::error('Loyalty points could not be added to a GDPR export', [
                'user'  => $event->user->id,
                'error' => $e->getMessage(),
            ]);

            $event->caveat(
                'The loyalty programme could not be read while this file was generated, so any '
                . 'points balance and history are not included here. Ask the store to re-run it.'
            );
        }
    }

    private function contribute(GdprCollecting $event): void
    {
        $member = LoyaltyMember::with('tier')
            ->where('user_id', $event->user->id)
            ->first();

        if ($member === null) {
            return;
        }

        $event->contribute('plugin:loyalty-points', [
            'membership' => [
                'joined_at'       => optional($member->joined_at)->toIso8601String(),
                'status'          => $member->status,
                'balance'         => (int) $member->balance,
                'lifetime_points' => (int) $member->lifetime_points,
                'tier'            => $member->tier?->title,
            ],

            // Ordered oldest first: this is read as a history, which is the opposite of how
            // the admin list is read.
            'points_history' => $member->transactions()
                ->orderBy('id')
                ->get()
                ->map(fn ($entry) => [
                    'recorded_at' => optional($entry->created_at)->toIso8601String(),
                    'type'        => $entry->type,
                    'points'      => (int) $entry->points,
                    'reason'      => $entry->reason,
                    'order_id'    => $entry->order_id,
                    'corrects_entry' => $entry->reverses_id,
                ])
                ->all(),
        ]);

        $event->caveat(
            'Loyalty points lists this person\'s membership and every entry in their points '
            . 'ledger. The "reason" on an entry is free text written by a member of staff.'
        );
    }
}

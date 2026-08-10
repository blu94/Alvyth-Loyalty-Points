<?php

namespace Plugin\LoyaltyPoints\Backend\Services;

use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * Retire points the customer sat on for longer than the programme allows.
 *
 * `expiry_months` was a stored number nothing read: an operator set a twelve-month policy,
 * saw it save, and points lasted forever. A setting that silently does nothing is worse than
 * no setting, because it is believed.
 *
 * **Operator-run, not scheduled.** A plugin registers no service provider and no console
 * command, so there is nowhere for this package to hang a cron entry — the same constraint
 * Redirect Manager's prune lives under. Pretending otherwise would put us straight back to a
 * policy that does nothing. It runs from a button, and the screen says when it last ran.
 *
 * **Oldest points go first, without tracking lots.** A per-batch ledger would be the textbook
 * answer and would need a second table, a consumption record per redemption, and a migration
 * for every existing member. The same answer falls out of arithmetic the ledger already
 * holds: everything credited before the cutoff, minus everything ever spent. Spending always
 * consumes the oldest points, so whatever survives that subtraction is old *and* unspent —
 * which is precisely what should expire.
 *
 * Expiry is written as an ordinary `expire` entry, never by editing a balance. The ledger
 * stays the only source of truth, the customer's history explains where the points went, and
 * `recalculate()` is what moves the number.
 */
class PointsExpiry
{
    /**
     * Expire what is due across every member.
     *
     * @return array{expired_members:int, expired_points:int, months:int|null}
     */
    public function run(): array
    {
        $settings = LoyaltySetting::current();
        $months   = $settings->expiry_months;

        // No policy means points do not expire. Not an error, and not something to warn
        // about — it is the default and a perfectly ordinary way to run a programme.
        if ($months === null || $months < 1) {
            return ['expired_members' => 0, 'expired_points' => 0, 'months' => null];
        }

        $cutoff  = now()->subMonths($months);
        $members = 0;
        $points  = 0;

        // Suspended members are skipped, matching redemption: their balance is frozen, and
        // freezing it while it quietly drains would be the worst of both.
        LoyaltyMember::where('status', 'active')
            ->where('balance', '>', 0)
            ->chunkById(200, function ($chunk) use ($cutoff, &$members, &$points) {
                foreach ($chunk as $member) {
                    $amount = $this->expirableFor($member, $cutoff);

                    if ($amount < 1) {
                        continue;
                    }

                    LoyaltyTransaction::create([
                        'member_id' => $member->id,
                        'points'    => -$amount,
                        'type'      => LoyaltyTransaction::TYPE_EXPIRE,
                        'reason'    => 'Expired after ' . $cutoff->diffForHumans(now(), true) . ' unused',
                    ]);

                    $member->recalculate();

                    $members++;
                    $points += $amount;
                }
            });

        return ['expired_members' => $members, 'expired_points' => $points, 'months' => $months];
    }

    /**
     * How many of this member's points are both old and unspent.
     *
     * Credited before the cutoff, less everything ever spent. The subtraction is what makes
     * it oldest-first: a redemption is assumed to have consumed the oldest points available,
     * so anything still standing after removing every debit must be the oldest credits that
     * nothing has reached yet.
     *
     * Capped at the current balance as a floor against arithmetic that should not be possible
     * — a member cannot lose more than they hold, whatever the ledger has accumulated.
     */
    private function expirableFor(LoyaltyMember $member, \DateTimeInterface $cutoff): int
    {
        $creditedBeforeCutoff = (int) $member->transactions()
            ->where('points', '>', 0)
            ->where('created_at', '<', $cutoff)
            ->sum('points');

        if ($creditedBeforeCutoff < 1) {
            return 0;
        }

        // Every debit, not only the old ones: a redemption last week still spends points
        // earned two years ago, which is the whole point of oldest-first.
        $spent = abs((int) $member->transactions()->where('points', '<', 0)->sum('points'));

        return max(0, min($creditedBeforeCutoff - $spent, (int) $member->balance));
    }
}

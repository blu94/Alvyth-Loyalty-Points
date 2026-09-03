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
 * the recalculation `Ledger::post()` performs is what moves the number.
 *
 * ## One run is a batch, and a batch is bounded
 *
 * `savePageData()` is wrapped in a transaction by `GenericModuleController`, so an unbounded
 * pass held every lock it took for the length of the whole sweep. At six queries per member
 * — two aggregates to size the expiry, the insert, and the recalculation — fifty thousand
 * eligible members is roughly three hundred thousand statements inside one open transaction:
 * a lock-wait timeout or `max_execution_time`, and a rollback of work the operator was about
 * to be told had succeeded.
 *
 * So a run stops after {@see self::BATCH} members and says so. Pressing the button again
 * continues, and doing it once too often costs nothing: the expiry entry is itself a debit
 * the next pass sees, so a member already retired has nothing left to retire.
 *
 * Every entry from one run shares a `batch` reference, which is what makes an accidental run
 * recoverable rather than merely regrettable.
 */
class PointsExpiry
{
    /**
     * Members retired per press.
     *
     * Sized for the transaction it runs inside rather than for throughput: a few thousand
     * members is a second or two of statements, which is well inside every default timeout
     * and short enough that the locks it holds are not felt by anyone else.
     */
    public const BATCH = 2000;

    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * Expire what is due, up to one batch.
     *
     * @return array{expired_members:int, expired_points:int, months:int|null, batch:string|null, more:bool}
     */
    public function run(): array
    {
        $settings = LoyaltySetting::current();
        $months   = $settings->expiry_months;

        // No policy means points do not expire. Not an error, and not something to warn
        // about — it is the default and a perfectly ordinary way to run a programme.
        if ($months === null || $months < 1) {
            return ['expired_members' => 0, 'expired_points' => 0, 'months' => null, 'batch' => null, 'more' => false];
        }

        $cutoff  = now()->subMonths($months);
        $batch   = 'EXP-' . now()->format('YmdHis');
        $members = 0;
        $points  = 0;

        // Suspended members are skipped, matching redemption: their balance is frozen, and
        // freezing it while it quietly drains would be the worst of both.
        LoyaltyMember::where('status', 'active')
            ->where('balance', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($chunk) use ($cutoff, $batch, &$members, &$points) {
                foreach ($chunk as $member) {
                    if ($members >= self::BATCH) {
                        return false;
                    }

                    $amount = $this->expirableFor($member, $cutoff);

                    if ($amount < 1) {
                        continue;
                    }

                    $this->ledger->post([
                        'member_id' => $member->id,
                        'points'    => -$amount,
                        'type'      => LoyaltyTransaction::TYPE_EXPIRE,
                        'reason'    => 'Expired after ' . $cutoff->diffForHumans(now(), true) . ' unused [' . $batch . ']',
                    ]);

                    $members++;
                    $points += $amount;
                }

                return true;
            });

        return [
            'expired_members' => $members,
            'expired_points'  => $points,
            'months'          => $months,
            'batch'           => $members > 0 ? $batch : null,

            // Whether the sweep stopped on the cap rather than on running out of members.
            // The screen turns this into "press again to continue", which is honest about
            // there being more to do instead of reporting a complete run that was not one.
            'more'            => $members >= self::BATCH,
        ];
    }

    /**
     * The most recent run that has not already been given back.
     *
     * What the toolbar's undo uses. A list toolbar cannot prompt for a value, and an undo an
     * operator can only reach by copying a reference out of a message they may already have
     * dismissed is not a control they have.
     *
     * Read from the reference stamped into the entry's own reason rather than from a column.
     * A batch is a property of one run, not of the ledger's shape, and a column would have to
     * be carried by every entry ever written to describe the few that belong to a sweep.
     */
    public function latestBatch(): ?string
    {
        $reason = LoyaltyTransaction::query()
            ->where('type', LoyaltyTransaction::TYPE_EXPIRE)
            ->where('reason', 'like', '%[EXP-%]')
            ->whereDoesntHave('reversals')
            ->orderByDesc('id')
            ->value('reason');

        if ($reason === null || ! preg_match('/\[(EXP-\d+)\]$/', $reason, $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Reverse one expiry batch, entry for entry.
     *
     * The run is irreversible in the sense that matters — it takes spendable balance from
     * every customer at once — and it is authorised by the same grant that adds a single
     * ledger row. Making it undoable is the control that actually helps: a mistaken press is
     * recoverable in one action instead of being reconstructed by hand from the ledger.
     *
     * Mirrored rather than deleted, for the reason every other correction here is: the ledger
     * says what happened, and what happened is that points were retired and then given back.
     * `reverses_id` on each mirror is what keeps standing untouched — reversing an `expire`
     * is not an earning.
     *
     * @return array{restored_points:int, restored_members:int, batch:string}
     */
    public function undo(string $batch): array
    {
        $entries = LoyaltyTransaction::query()
            ->where('type', LoyaltyTransaction::TYPE_EXPIRE)
            ->where('reason', 'like', '%[' . $batch . ']')
            ->whereDoesntHave('reversals')
            ->get();

        $points = 0;

        foreach ($entries as $entry) {
            $this->ledger->post([
                'member_id'   => $entry->member_id,
                'points'      => -$entry->points,
                'type'        => LoyaltyTransaction::TYPE_ADJUST,
                'reason'      => 'Reversed expiry batch ' . $batch,
                'reverses_id' => $entry->id,
            ]);

            $points += abs((int) $entry->points);
        }

        return [
            'restored_points'  => $points,
            'restored_members' => $entries->pluck('member_id')->unique()->count(),
            'batch'            => $batch,
        ];
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

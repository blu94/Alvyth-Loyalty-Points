<?php

namespace Plugin\LoyaltyPoints\Backend\Services;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * The one way an entry reaches the ledger.
 *
 * Five callers used to write the same two statements — `LoyaltyTransaction::create()`, then
 * `$member->recalculate()` — as two unrelated operations. Three separate rules had to hold at
 * every one of those sites, and each was a place to forget one:
 *
 * 1. **They must be one transaction.** A recompute that fails after the insert commits leaves
 *    the entry in the ledger and the member's cached totals stale, with nothing to detect it.
 * 2. **The member row must be locked.** `recalculate()` reads three aggregates and writes the
 *    row; an entry landing between the read and the write is not reflected until some later
 *    write happens to occur.
 * 3. **A duplicate order entry must be absorbed, not thrown.** The uniqueness guard added in
 *    `…_guard_one_entry_per_order_and_type_…` is what actually makes awarding idempotent; a
 *    caller that lets the exception escape turns a harmless second delivery into a 500.
 *
 * Collapsing them here is what makes those three true everywhere at once, and it is why the
 * listeners below are now short enough to read.
 *
 * **Stores exactly what it is given.** The sign rule lives in
 * {@see LoyaltyTransaction::signedPoints()} and is applied by the caller, unchanged: a
 * writer that silently re-signed its input would be a second place for the rule to live and
 * the two would drift.
 */
class Ledger
{
    /**
     * Post one entry and bring the member's totals with it.
     *
     * @param  array<string,mixed>  $attributes
     * @return LoyaltyTransaction|null  the entry, or null if an identical order entry already existed
     */
    public function post(array $attributes): ?LoyaltyTransaction
    {
        return DB::transaction(function () use ($attributes) {
            $member = $this->lock((int) ($attributes['member_id'] ?? 0));

            if ($member === null) {
                return null;
            }

            try {
                $entry = LoyaltyTransaction::create($attributes);
            } catch (QueryException $e) {
                // The uniqueness guard fired: another delivery of the same payment got here
                // first and the work is already done. Idempotent means answering "already
                // handled" rather than raising — see the guard migration for why the
                // constraint, and not a preceding SELECT, is what settles the race.
                if ($this->isDuplicate($e)) {
                    return null;
                }

                throw $e;
            }

            $member->recalculate();

            return $entry;
        });
    }

    /**
     * Recompute one member's totals under the same lock a write takes.
     *
     * For the callers that changed the ledger without adding to it — an edited or deleted
     * entry, a re-ranked tier ladder — where the totals still have to follow.
     */
    public function refresh(?int $memberId): void
    {
        if ($memberId === null) {
            return;
        }

        DB::transaction(function () use ($memberId) {
            $this->lock($memberId)?->recalculate();
        });
    }

    /**
     * The member row, held for the rest of the transaction.
     *
     * `lockForUpdate()` rather than an optimistic re-read: two entries for the same member
     * arriving together would otherwise both aggregate the ledger as it was before either
     * landed, and the second `save()` would write a total that is already out of date.
     */
    private function lock(int $memberId): ?LoyaltyMember
    {
        if ($memberId < 1) {
            return null;
        }

        return LoyaltyMember::whereKey($memberId)->lockForUpdate()->first();
    }

    /**
     * Whether this failure is the uniqueness guard rather than a real fault.
     *
     * Matched on SQLSTATE 23000 — integrity constraint violation — which is what every
     * driver reports for a duplicate key, rather than on MySQL's 1062 or on the message
     * text. A foreign-key failure shares the class and is genuinely exceptional, so the
     * constraint name is checked too: only the guard is absorbed.
     */
    private function isDuplicate(QueryException $e): bool
    {
        return (string) $e->getCode() === '23000'
            && str_contains($e->getMessage(), 'loyalty_tx_order_type_unique');
    }
}

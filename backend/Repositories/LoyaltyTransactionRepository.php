<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;

/**
 * The ledger.
 *
 * Every write here goes through {@see Ledger}, which is what guarantees the affected member's
 * totals follow in the same transaction and under the same lock. That is the whole contract
 * of this class: nothing may change the ledger without the totals following.
 *
 * ## Entries are append-only, and now actually are
 *
 * The create migration said rows were "append-only in spirit" and the README told operators
 * to correct a mistake with a compensating `adjust` entry. The module then shipped edit and
 * delete actions and this class implemented both, including moving an entry between members.
 *
 * Deleting an entry destroys the fact a balance was computed from. Because balances are
 * recomputed rather than incremented the totals stayed self-consistent, which is exactly what
 * made the loss invisible: the numbers still added up and the history explaining them was
 * gone. So `delete()` refuses, and `update()` now accepts the reason and nothing else — the
 * escape hatch for an operator who fat-fingered 5,000 is the `adjust` entry the README always
 * described, and it leaves both numbers in the history.
 */
class LoyaltyTransactionRepository
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function baseIndexQuery(array $filters = [])
    {
        $query = LoyaltyTransaction::query()->with(['member.user', 'author']);

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['member_id'])) {
            $query->where('member_id', $filters['member_id']);
        }

        // Newest first: a ledger is read as history, and the default ascending id order
        // buries today's activity behind every entry ever made.
        return $query->orderByDesc('id');
    }

    public function create(array $data)
    {
        $data['points'] = LoyaltyTransaction::signedPoints(
            $data['type'] ?? LoyaltyTransaction::TYPE_EARN,
            $data['points'] ?? 0
        );

        // Who is doing this, stamped here rather than inside the Ledger.
        //
        // This is the one path where the authenticated user really is the author. In a
        // storefront checkout the authenticated user is the *customer*, so a blanket
        // `auth()->id()` inside the writer would name the buyer as the author of a system
        // entry — and a wrong name on an audit trail is worse than no name.
        $data['created_by'] = auth()->id();

        $transaction = $this->ledger->post($data);

        return $transaction?->load('member.user', 'author');
    }

    public function find($id)
    {
        return LoyaltyTransaction::with(['member.user', 'author'])->find($id);
    }

    /**
     * Correct the reason, and nothing else.
     *
     * `points`, `type` and `member_id` are what a balance is computed from; changing one
     * rewrites history that other entries were reconciled against. Stripped here rather than
     * only hidden on the form, for the same reason `LoyaltyMemberRepository::update()` strips
     * the derived columns: the screen not offering a field is not the same as the endpoint
     * refusing it.
     */
    public function update($id, array $data)
    {
        $transaction = LoyaltyTransaction::findOrFail($id);

        $transaction->update(['reason' => $data['reason'] ?? $transaction->reason]);

        return $transaction->load('member.user', 'author');
    }

    /**
     * Refused, the way `create()` on a read-only module is.
     *
     * An entry is evidence that something happened. Removing it makes the ledger claim
     * otherwise, and because every total is recomputed from what remains, the screens would
     * agree with the new, false history without a mark to say anything had gone.
     */
    public function delete($id)
    {
        throw new \RuntimeException(
            'Ledger entries cannot be deleted — the balance every screen shows is computed from them. '
            . 'Post an Adjust entry to correct a mistake; it leaves both numbers in the history.'
        );
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['type']) as $column) {
            $out[$column] = LoyaltyTransaction::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }
}

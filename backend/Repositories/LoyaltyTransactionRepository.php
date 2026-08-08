<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * The ledger.
 *
 * Every write here ends with the affected member being recomputed, because the member's
 * balance has no other source. That is the whole contract of this class: nothing may
 * change the ledger without the totals following.
 */
class LoyaltyTransactionRepository
{
    public function baseIndexQuery(array $filters = [])
    {
        $query = LoyaltyTransaction::query()->with(['member.user']);

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

        $transaction = LoyaltyTransaction::create($data);

        $this->recalculateMember($transaction->member_id);

        return $transaction->load('member.user');
    }

    public function find($id)
    {
        return LoyaltyTransaction::with(['member.user'])->find($id);
    }

    public function update($id, array $data)
    {
        $transaction = LoyaltyTransaction::findOrFail($id);
        $previousMember = $transaction->member_id;

        if (array_key_exists('points', $data) || array_key_exists('type', $data)) {
            $data['points'] = LoyaltyTransaction::signedPoints(
                $data['type'] ?? $transaction->type,
                $data['points'] ?? $transaction->points
            );
        }

        $transaction->update($data);

        // Both members, when an entry is moved between them — otherwise the one it left
        // keeps points it no longer has any transaction for.
        $this->recalculateMember($previousMember);
        $this->recalculateMember($transaction->member_id);

        return $transaction->load('member.user');
    }

    public function delete($id)
    {
        $transaction = LoyaltyTransaction::find($id);

        if (! $transaction) {
            return 0;
        }

        $memberId = $transaction->member_id;
        $deleted  = $transaction->delete();

        $this->recalculateMember($memberId);

        return $deleted;
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['type']) as $column) {
            $out[$column] = LoyaltyTransaction::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }

    private function recalculateMember(?int $memberId): void
    {
        if ($memberId === null) {
            return;
        }

        LoyaltyMember::find($memberId)?->recalculate();
    }
}

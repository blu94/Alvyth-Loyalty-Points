<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;

class LoyaltyTierRepository
{
    public function __construct(private Ledger $ledger)
    {
    }

    public function baseIndexQuery(array $filters = [])
    {
        $query = LoyaltyTier::query()->withCount('members');

        // `!empty`, not `isset`: DataTables sends `status=` when the filter is cleared,
        // and `isset('')` is true — which would silently filter the list down to nothing.
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        return $query;
    }

    public function create(array $data)
    {
        $tier = LoyaltyTier::create($data);

        // A new tier can only promote, and only members already at or above its threshold.
        // Everyone below it is unaffected by definition, so they are not walked.
        $this->restandMembers(fromLifetime: (int) $tier->threshold);

        return $tier;
    }

    public function find($id)
    {
        return LoyaltyTier::withCount('members')->find($id);
    }

    public function update($id, array $data)
    {
        $tier = LoyaltyTier::findOrFail($id);

        $before = (int) $tier->threshold;
        $wasActive = $tier->status === 'active';

        $tier->update($data);

        $after = (int) $tier->threshold;

        // Moving a threshold changes who qualifies from the lower of the two figures upward:
        // raising it demotes the band between old and new, lowering it promotes the same
        // band, and nobody beneath either figure is touched either way.
        //
        // Switching a tier off is different in kind — its own members must be re-ranked
        // whatever their standing — so that case walks from zero rather than from a
        // threshold.
        $statusChanged = $wasActive !== ($tier->status === 'active');

        $this->restandMembers(fromLifetime: $statusChanged ? 0 : min($before, $after));

        return $tier;
    }

    public function delete($id)
    {
        $tier = LoyaltyTier::find($id);

        if ($tier === null) {
            return 0;
        }

        $deleted = LoyaltyTier::destroy($id);

        // Only the members who stood in it can have moved. The foreign key nulls their
        // `tier_id` on delete, so they are found by that before the pass rather than after.
        $this->restandMembers(fromLifetime: (int) $tier->threshold);

        return $deleted;
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['status']) as $column) {
            $out[$column] = LoyaltyTier::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }

    /**
     * Re-rank the members a tier change could actually have moved.
     *
     * Editing a threshold, switching a tier off or deleting one silently changes who
     * qualifies for what. Without this the members list keeps showing standings that the
     * current rules no longer produce — and the discrepancy only surfaces the next time that
     * member happens to earn a point, which could be months.
     *
     * **Bounded by lifetime points rather than run over everyone.** The pass used to walk the
     * whole members table on every tier write, at four queries each, inside the transaction
     * `GenericModuleController` opens — so a shop with a real membership held locks across
     * hundreds of thousands of statements to correct a set that is usually tiny. A tier change
     * can only move members standing at or above the lower of its old and new thresholds;
     * everyone below both keeps exactly the tier they had.
     *
     * Still a full walk of that band, because a single threshold move can push members in
     * both directions within it and the affected set is not derivable from the tier alone.
     *
     * Each member is recomputed through {@see Ledger} so the write takes the same row lock a
     * ledger entry would, and cannot cross with one arriving from a paid order mid-pass.
     */
    private function restandMembers(int $fromLifetime = 0): void
    {
        // No `orWhereNull('tier_id')` alongside this. It reads like the safe addition — catch
        // the untiered too — and it is the one clause that would undo the bound, because on a
        // young programme every member is untiered. It is also unnecessary: a member whose
        // tier was just deleted stood in it, so their lifetime is at or above its threshold
        // and the comparison already has them.
        LoyaltyMember::query()
            ->where('lifetime_points', '>=', $fromLifetime)
            ->chunkById(200, function ($members) {
                foreach ($members as $member) {
                    $this->ledger->refresh($member->id);
                }
            });
    }
}

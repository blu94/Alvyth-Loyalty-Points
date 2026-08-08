<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;

class LoyaltyTierRepository
{
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

        $this->restandMembers();

        return $tier;
    }

    public function find($id)
    {
        return LoyaltyTier::withCount('members')->find($id);
    }

    public function update($id, array $data)
    {
        $tier = LoyaltyTier::findOrFail($id);
        $tier->update($data);

        $this->restandMembers();

        return $tier;
    }

    public function delete($id)
    {
        $deleted = LoyaltyTier::destroy($id);

        $this->restandMembers();

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
     * Re-rank every member after the tier ladder changes.
     *
     * Editing a threshold, switching a tier off or deleting one silently changes who
     * qualifies for what. Without this the members list keeps showing standings that the
     * current rules no longer produce — and the discrepancy only surfaces the next time
     * that member happens to earn a point, which could be months.
     *
     * A full pass, because a threshold change can move members in both directions at once
     * and the set affected is not derivable from the tier alone. Tier edits are rare and
     * operator-initiated; the members table is the one that grows, so if a shop ever gets
     * large enough for this to hurt, this is the line to move to a queued job.
     */
    private function restandMembers(): void
    {
        LoyaltyMember::query()->with('transactions')->chunkById(200, function ($members) {
            foreach ($members as $member) {
                $member->recalculate();
            }
        });
    }
}

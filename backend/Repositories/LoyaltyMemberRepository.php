<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;

/**
 * Members — who is in the programme and what they are worth.
 *
 * Also serves the programme's two custom pages. `GenericModuleController` routes
 * `/{module}/page/{slug}` to `pageData()`/`savePageData()` when the repository defines
 * them, which is the only way a plugin can ship a screen that is not CRUD.
 */
class LoyaltyMemberRepository
{
    public function baseIndexQuery(array $filters = [])
    {
        // Eager-loaded because the list renders `user.name` and `tier.title` on every row;
        // without this the grid is two queries per row.
        $query = LoyaltyMember::query()->with(['user', 'tier']);

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['tier_id'])) {
            $query->where('tier_id', $filters['tier_id']);
        }

        return $query;
    }

    public function create(array $data)
    {
        // Enrolling is the one moment the operator picks the date; everything after is
        // ledger-driven. Defaulted rather than exposed as a field nobody would fill in.
        $data['joined_at'] = $data['joined_at'] ?? now();

        $member = LoyaltyMember::create($data);

        // Zero out from the (empty) ledger rather than trusting whatever was posted:
        // balance is derived, so accepting it from a form would be the one way to make
        // it disagree with the transactions.
        return $member->recalculate();
    }

    public function find($id)
    {
        return LoyaltyMember::with(['user', 'tier'])->find($id);
    }

    public function update($id, array $data)
    {
        $member = LoyaltyMember::findOrFail($id);

        // `balance`, `lifetime_points` and `tier_id` are derived from the ledger and from
        // the tier thresholds. Stripping them here means a hand-crafted request cannot
        // set a balance that no transaction accounts for — the screen never offers them,
        // but the endpoint is what has to enforce it.
        unset($data['balance'], $data['lifetime_points'], $data['tier_id']);

        $member->update($data);

        return $member->recalculate();
    }

    public function delete($id)
    {
        // The ledger goes with the member (cascade on the foreign key): points are
        // meaningless without whose they are, and leaving orphans would corrupt every
        // total on the overview.
        return LoyaltyMember::destroy($id);
    }

    public function getOptions(array $columns = [])
    {
        $out = [];

        foreach (array_intersect($columns, ['status']) as $column) {
            $out[$column] = LoyaltyMember::query()->distinct()->pluck($column)->filter()->values();
        }

        return $out;
    }

    // -----------------------------------------------------------------------
    // Custom pages
    // -----------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    public function pageData(string $slug): array
    {
        return match ($slug) {
            'overview' => $this->overview(),
            'settings' => LoyaltySetting::current()->toArray(),
            default    => [],
        };
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    public function savePageData(string $slug, array $data): array
    {
        if ($slug !== 'settings') {
            return [];
        }

        $settings = LoyaltySetting::current();

        // Whitelisted, not `$request->all()`: the page posts its whole bound model back,
        // which on the settings screen includes `id` and the timestamps it was loaded with.
        $settings->update([
            'points_per_currency' => max(0, (float) ($data['points_per_currency'] ?? 0)),
            'redeem_value'        => max(0, (float) ($data['redeem_value'] ?? 0)),
            'expiry_months'       => ($data['expiry_months'] ?? null) !== null && $data['expiry_months'] !== ''
                ? max(0, (int) $data['expiry_months'])
                : null,
            'minimum_redemption'  => max(0, (int) ($data['minimum_redemption'] ?? 0)),
        ]);

        return $settings->fresh()->toArray();
    }

    /**
     * Programme-wide totals.
     *
     * `issued` and `redeemed` are summed from the ledger rather than from the members'
     * cached balances, because a balance is a net figure — it cannot tell you that a
     * customer earned 500 and spent 500. Those two numbers are the ones an operator
     * actually asks for, and the difference between them is the outstanding liability.
     */
    private function overview(): array
    {
        $issued   = (int) LoyaltyTransaction::where('points', '>', 0)->sum('points');
        $redeemed = (int) abs((int) LoyaltyTransaction::where('points', '<', 0)->sum('points'));

        $settings = LoyaltySetting::current();

        $perTier = LoyaltyTier::query()
            ->orderBy('threshold')
            ->withCount('members')
            ->get()
            ->map(fn ($tier) => "{$tier->title}: {$tier->members_count}")
            ->implode('  ·  ');

        return [
            'members_total'     => LoyaltyMember::count(),
            'members_active'    => LoyaltyMember::where('status', 'active')->count(),
            'points_issued'     => $issued,
            'points_redeemed'   => $redeemed,
            'points_outstanding' => $issued - $redeemed,

            // What the outstanding balance would cost the shop if every customer redeemed
            // tomorrow. This is the number a finance team asks for, and it is why
            // `redeem_value` is a setting rather than a hard-coded rate.
            'liability'         => number_format(($issued - $redeemed) * (float) $settings->redeem_value, 2),

            'unenrolled_customers' => DB::table('users')
                ->whereNotIn('id', LoyaltyMember::query()->select('user_id'))
                ->count(),

            'tier_breakdown'    => $perTier !== '' ? $perTier : 'No tiers defined yet',
        ];
    }
}

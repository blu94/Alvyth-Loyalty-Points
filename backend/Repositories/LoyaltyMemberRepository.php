<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use Illuminate\Support\Facades\DB;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\PointsExpiry;

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
        // A plugin registers no routes, so `POST /admin/modules/{type}/page/{slug}` is the
        // only way a schema-declared button reaches the server. Each slug is one button.
        if ($slug === 'expire') {
            return $this->runExpiry();
        }

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
     * Retire points older than the programme's window, on demand.
     *
     * Returns a sentence rather than a count on its own: an operator pressing this needs to
     * know whether it did nothing because nothing was due, or because no policy is set. Those
     * look identical from a zero.
     *
     * @return array<string,mixed>
     */
    private function runExpiry(): array
    {
        $result = app(PointsExpiry::class)->run();

        if ($result['months'] === null) {
            return $result + ['message' => 'No expiry window is set, so no points expire. Set one in Settings first.'];
        }

        if ($result['expired_points'] === 0) {
            return $result + ['message' => 'Nothing to expire — no points are older than ' . $result['months'] . ' months.'];
        }

        return $result + ['message' => sprintf(
            'Expired %s points across %d %s.',
            number_format($result['expired_points']),
            $result['expired_members'],
            $result['expired_members'] === 1 ? 'member' : 'members'
        )];
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

            'tier_distribution' => $this->tierDistribution(),
            'points_flow'       => $this->pointsFlow(),
            'enrolments'        => $this->enrolments(),
            'top_members'       => $this->topMembers(),
        ];
    }

    /**
     * Members per tier, as a whole.
     *
     * **"No tier" is a slice, not an omission.** A pie is only honest when its parts sum to
     * the population it claims to describe, and everyone below the lowest threshold is a
     * real member — dropping them would show a programme where every customer has standing.
     * On a new programme that slice IS the programme, which is exactly what the operator
     * needs to see.
     *
     * Disabled tiers are included when they still hold members: `recalculate()` only stops
     * *awarding* a switched-off tier, so members already standing in one keep it until their
     * next ledger write. Hiding it would lose those members from the total.
     *
     * @return array{labels:array<int,string>,series:array<int,int>}
     */
    private function tierDistribution(): array
    {
        $tiers = LoyaltyTier::query()
            ->orderBy('threshold')
            ->withCount('members')
            ->get()
            ->filter(fn ($tier) => $tier->status === 'active' || $tier->members_count > 0);

        $labels = $tiers->map(fn ($tier) => (string) $tier->title)->values()->all();
        $series = $tiers->map(fn ($tier) => (int) $tier->members_count)->values()->all();

        $untiered = LoyaltyMember::whereNull('tier_id')->count();

        if ($untiered > 0) {
            $labels[] = 'No tier yet';
            $series[] = $untiered;
        }

        return ['labels' => $labels, 'series' => $series];
    }

    /**
     * Points issued against points redeemed, by month.
     *
     * The one chart that answers "is this programme accumulating a liability?" — issued
     * consistently above redeemed means points are piling up unspent, which is a cost the
     * balance sheet has not met yet.
     *
     * Both series are drawn positive. Redemptions are stored negative (the ledger's sign
     * carries meaning), but a bar chart of one positive and one negative series reads as a
     * cancellation rather than a comparison.
     *
     * @return array{labels:array<int,string>,series:array<int,array{name:string,data:array<int,int>}>}
     */
    private function pointsFlow(): array
    {
        $months = $this->recentMonths();

        $rows = LoyaltyTransaction::query()
            ->where('created_at', '>=', now()->startOfMonth()->subMonths(self::MONTHS - 1))
            ->selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym")
            ->selectRaw('SUM(CASE WHEN points > 0 THEN points ELSE 0 END) as issued')
            ->selectRaw('SUM(CASE WHEN points < 0 THEN -points ELSE 0 END) as redeemed')
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        return [
            'labels' => array_values($months),
            'series' => [
                [
                    'name' => 'Issued',
                    'data' => $this->alignToMonths($months, $rows, 'issued'),
                ],
                [
                    'name' => 'Redeemed',
                    'data' => $this->alignToMonths($months, $rows, 'redeemed'),
                ],
            ],
        ];
    }

    /**
     * New members per month — how fast the programme is recruiting.
     *
     * Counted on `joined_at`, not `created_at`: enrolling a customer who has been shopping
     * for a year is an event the operator dates themselves, and the row's insert time would
     * report it as new business this month.
     *
     * @return array{labels:array<int,string>,series:array<int,array{name:string,data:array<int,int>}>}
     */
    private function enrolments(): array
    {
        $months = $this->recentMonths();

        $rows = LoyaltyMember::query()
            ->where('joined_at', '>=', now()->startOfMonth()->subMonths(self::MONTHS - 1))
            ->selectRaw("DATE_FORMAT(joined_at, '%Y-%m') as ym")
            ->selectRaw('COUNT(*) as total')
            ->groupBy('ym')
            ->get()
            ->keyBy('ym');

        return [
            'labels' => array_values($months),
            'series' => [[
                'name' => 'Joined',
                'data' => $this->alignToMonths($months, $rows, 'total'),
            ]],
        ];
    }

    /**
     * The largest unspent balances.
     *
     * Balance, not lifetime points: this is the question "who is holding the liability",
     * and a customer who earned and spent 10,000 costs the shop nothing today.
     *
     * @return array{labels:array<int,string>,series:array<int,array{name:string,data:array<int,int>}>}
     */
    private function topMembers(): array
    {
        $members = LoyaltyMember::query()
            ->with('user')
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->limit(8)
            ->get();

        return [
            'labels' => $members
                ->map(fn ($member) => $member->user->name ?? "Member #{$member->id}")
                ->all(),
            'series' => [[
                'name' => 'Balance',
                'data' => $members->map(fn ($member) => (int) $member->balance)->all(),
            ]],
        ];
    }

    /** How many months the trend charts cover. */
    private const MONTHS = 12;

    /**
     * The last twelve months as `['2026-08' => 'Aug 2026', …]`, oldest first.
     *
     * Built in PHP rather than taken from the query results so a month with no activity is
     * a **zero**, not a missing column. A chart that silently drops empty months compresses
     * a quiet period into nothing and makes the trend look steadier than it was.
     *
     * @return array<string,string>
     */
    private function recentMonths(): array
    {
        $months = [];
        $cursor = now()->startOfMonth()->subMonths(self::MONTHS - 1);

        for ($i = 0; $i < self::MONTHS; $i++) {
            $months[$cursor->format('Y-m')] = $cursor->format('M Y');
            $cursor = $cursor->copy()->addMonth();
        }

        return $months;
    }

    /**
     * @param  array<string,string>  $months
     * @param  \Illuminate\Support\Collection<string,object>  $rows  aggregates keyed by `Y-m`
     * @return array<int,int>
     */
    private function alignToMonths(array $months, $rows, string $column): array
    {
        return array_map(
            fn ($ym) => (int) ($rows->get($ym)->{$column} ?? 0),
            array_keys($months)
        );
    }
}

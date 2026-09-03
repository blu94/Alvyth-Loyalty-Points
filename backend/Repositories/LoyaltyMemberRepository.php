<?php

namespace Plugin\LoyaltyPoints\Backend\Repositories;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyMember;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltySetting;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTier;
use Plugin\LoyaltyPoints\Backend\Models\LoyaltyTransaction;
use Plugin\LoyaltyPoints\Backend\Services\Ledger;
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
    public function __construct(private Ledger $ledger)
    {
    }

    /**
     * Who counts as a customer.
     *
     * Pinned here and used by both the Overview's "not yet enrolled" figure and the
     * enrolment picker, because the two disagreeing about the population is how the count
     * came to include people who could never enrol.
     *
     * Three conditions, each for its own reason. Through the **model** rather than
     * `DB::table()`, so `User`'s `SoftDeletes` global scope applies and a deleted customer
     * stops being counted as a prospect. **Active**, matching core's own customer picker.
     * And **holding no staff role**: administrators and editors live in the same `users`
     * table as customers, so without this every member of staff was a customer the
     * programme had failed to recruit.
     */
    public static function customers()
    {
        return User::query()
            ->where('status', 'active')
            ->whereDoesntHave('roles');
    }

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
        $this->ledger->refresh($member->id);

        return $member->refresh();
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

        $this->ledger->refresh($member->id);

        return $member->refresh();
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

        // The enrolment picker, served from here rather than from core's
        // `/admin/users/options`.
        //
        // That endpoint answers with every active user and applies no role filter, so the
        // list of "customers to enrol" offered every administrator and editor in the shop —
        // and enrolling one put a staff account into the members table and every tier figure.
        // Core's endpoint is right for what it is; this module needs a narrower question
        // asked, and `customers()` is the same predicate the Overview counts with.
        if (in_array('user_id', $columns, true)) {
            $out['user_id'] = self::customers()
                ->whereNotIn('id', LoyaltyMember::query()->select('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email'])
                ->map(fn ($user) => [
                    'title' => $user->name . ' (' . $user->email . ')',
                    'value' => $user->id,
                ])
                ->values();
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

        if ($slug === 'expire-undo') {
            return $this->undoExpiry($data);
        }

        if ($slug !== 'settings') {
            return [];
        }

        return $this->saveSettings($data);
    }

    /**
     * Write the programme's rules, or refuse and say why.
     *
     * **Validated rather than clamped.** These arrive as `$request->all()` — `ModuleRequest`
     * validates CRUD payloads against the form schema and a custom page is not one — and the
     * old code coerced whatever turned up with `max(0, (float) …)`. A non-numeric earn rate
     * therefore cast to `0`, saved successfully, and stopped the programme awarding anything;
     * the screen reported success and nothing anywhere said the rate was now zero. Silently
     * becoming zero is the one outcome an operator cannot see, which makes clamping the wrong
     * instinct here even though it never throws.
     *
     * Maxima match the column precision, so a value too large for `decimal(8,2)` comes back
     * as a message on the field instead of a driver error surfacing as a 500.
     *
     * `ValidationException` is thrown deliberately: `GenericModuleController` wraps this in a
     * transaction and catches `\Throwable`, so the save is rolled back and the operator is
     * told, rather than a bad rate being half-applied.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function saveSettings(array $data): array
    {
        // Blanks arrive from number inputs the operator cleared. For the two nullable rules
        // that means "no policy"; for the required ones it means the field was emptied, and
        // the validator should say so rather than a cast turning it into zero.
        foreach (['expiry_months', 'max_redemption_percent'] as $nullable) {
            if (($data[$nullable] ?? null) === '') {
                $data[$nullable] = null;
            }
        }

        $validated = Validator::make($data, [
            'points_per_currency'    => ['required', 'numeric', 'min:0', 'max:999999.99'],
            'earn_base'             => ['required', 'in:' . implode(',', LoyaltySetting::EARN_BASES)],
            'redeem_value'           => ['required', 'numeric', 'min:0', 'max:9999.9999'],
            'expiry_months'          => ['nullable', 'integer', 'min:1', 'max:1200'],
            'minimum_redemption'     => ['required', 'integer', 'min:0', 'max:4294967295'],
            'max_redemption_percent' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'points_per_currency.required' => 'Set an earn rate. A blank rate would stop the programme awarding anything.',
            'redeem_value.required'        => 'Set what a point is worth. A blank value would make every balance unredeemable.',
            'expiry_months.min'            => 'An expiry window of zero months would retire points the moment they are earned. Leave it empty for "never".',
            'max_redemption_percent.max'   => 'A cap above 100% is the same as no cap — leave it empty instead.',
        ])->validate();

        $settings = LoyaltySetting::current();

        // Whitelisted from the validated set, not from `$data`: the page posts its whole
        // bound model back, which on the settings screen includes `id` and the timestamps it
        // was loaded with.
        $settings->update([
            'points_per_currency'    => $validated['points_per_currency'],
            'earn_base'              => $validated['earn_base'],
            'redeem_value'           => $validated['redeem_value'],
            'expiry_months'          => $validated['expiry_months'] ?? null,
            'minimum_redemption'     => $validated['minimum_redemption'],
            'max_redemption_percent' => $validated['max_redemption_percent'] ?? null,
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

        $message = sprintf(
            'Expired %s points across %d %s. Reference %s — keep it if you need to undo this.',
            number_format($result['expired_points']),
            $result['expired_members'],
            $result['expired_members'] === 1 ? 'member' : 'members',
            $result['batch']
        );

        // A run stops at the batch ceiling rather than sweeping the whole programme inside
        // one transaction. Saying so is the difference between an operator knowing the job
        // is half done and believing it finished.
        if ($result['more']) {
            $message .= ' There are more to do — press again to continue.';
        }

        return $result + ['message' => $message];
    }

    /**
     * Give back one expiry batch.
     *
     * Bulk expiry is the only irreversible write in the package and it is authorised by the
     * same grant that adds a single ledger row. Making it undoable is the control that
     * actually helps: a mistaken press is recoverable in one action rather than reconstructed
     * by hand from the ledger.
     *
     * Restored with mirror entries carrying `reverses_id`, never by deleting the expiries —
     * the points were retired, and then they were given back, and the history should say both.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function undoExpiry(array $data): array
    {
        $expiry = app(PointsExpiry::class);

        // Defaults to the most recent run that has not already been given back. A list
        // toolbar has nowhere to type a reference, and an undo an operator cannot reach
        // without one is not a control they have.
        $batch = trim((string) ($data['batch'] ?? '')) ?: $expiry->latestBatch();

        if ($batch === null || $batch === '') {
            return ['message' => 'There is no expiry run left to undo.'];
        }

        $result = $expiry->undo($batch);

        if ($result['restored_points'] === 0) {
            return $result + ['message' => 'Nothing to undo under reference ' . $batch . '. Either it does not exist, or it has already been reversed.'];
        }

        return $result + ['message' => sprintf(
            'Restored %s points to %d %s from run %s.',
            number_format($result['restored_points']),
            $result['restored_members'],
            $result['restored_members'] === 1 ? 'member' : 'members',
            $batch
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
        // Counted by what an entry *means*, not by its sign.
        //
        // "Every positive" and "every negative" is the obvious split and it told two lies. A
        // refund writes a positive mirror of the redemption it undoes, which read as the shop
        // issuing more points; and expiry is negative, so points nobody ever spent were
        // reported as redeemed. Both nets came out right, which is exactly why neither was
        // visible: only the three figures separately were wrong.
        //
        // `points_issued` uses the same rule as `lifetime_points` — earn, the operator's own
        // adjustments, and corrections to earnings — so the headline figure and every
        // member's standing are computed from one definition of "earned".
        $issued = (int) LoyaltyTransaction::query()
            ->where(function ($outer) {
                $outer
                    ->where(fn ($q) => $q
                        ->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_ADJUST])
                        ->whereNull('reverses_id'))
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('reverses_id')
                        ->whereHas('reverses', fn ($r) => $r->where('type', LoyaltyTransaction::TYPE_EARN)));
            })
            ->sum('points');

        // Spending, and the undoing of spending. A refunded redemption is not a redemption.
        $redeemed = -(int) LoyaltyTransaction::query()
            ->where(function ($outer) {
                $outer
                    ->where('type', LoyaltyTransaction::TYPE_REDEEM)
                    ->orWhere(fn ($q) => $q
                        ->whereNotNull('reverses_id')
                        ->whereHas('reverses', fn ($r) => $r->where('type', LoyaltyTransaction::TYPE_REDEEM)));
            })
            ->sum('points');

        // Its own figure now that expiry actually runs. Lumping it into redemptions told the
        // operator customers had spent points that in fact ran out unused — the opposite
        // conclusion about how well the programme is working.
        $expired = -(int) LoyaltyTransaction::where('type', LoyaltyTransaction::TYPE_EXPIRE)->sum('points');

        // Issued less spent less expired. Every entry belongs to exactly one of the three, so
        // this still equals the sum of the whole ledger — the tiles add up on screen and the
        // total agrees with every member's balance.
        $outstanding = $issued - $redeemed - $expired;

        $settings = LoyaltySetting::current();

        return [
            'members_total'     => LoyaltyMember::count(),
            'members_active'    => LoyaltyMember::where('status', 'active')->count(),
            'points_issued'     => $issued,
            'points_redeemed'   => $redeemed,
            'points_expired'    => $expired,
            'points_outstanding' => $outstanding,

            // What the outstanding balance would cost the shop if every customer redeemed
            // tomorrow. This is the number a finance team asks for, and it is why
            // `redeem_value` is a setting rather than a hard-coded rate.
            'liability'         => number_format($outstanding * (float) $settings->redeem_value, 2),

            // Counted through the model and filtered to actual customers.
            //
            // This was `DB::table('users')` with no filter at all, which got it wrong three
            // ways at once: the raw builder bypasses `User`'s `SoftDeletes` scope, so deleted
            // customers counted as prospects; there was no `status` filter, so deactivated
            // accounts counted; and there was no role filter, so every administrator and
            // editor in the shop was a customer the programme had failed to recruit. On the
            // one figure that tells an owner how much growth is left.
            'unenrolled_customers' => self::customers()
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

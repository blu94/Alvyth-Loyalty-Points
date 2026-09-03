<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Make "one earn and one redemption per order" a constraint instead of a hope.
 *
 * Both listeners guarded with `where(order_id)->where(type)->exists()` and then inserted.
 * Two concurrent deliveries of the same `payment_status → paid` transition clear that read
 * at the same instant and both write, and the customer is credited twice for one payment.
 * `markPaid()` claims its own transition atomically, but `transitionStatus()` — the admin
 * form save and the status endpoint — guards on a value read off a loaded model and
 * dispatches anyway, so an operator saving while a gateway webhook lands is ordinary traffic.
 *
 * **MySQL's unique index permits many NULLs**, which is exactly the behaviour wanted here:
 * manual entries, expiry entries and anything else without an order carry `order_id = NULL`
 * and are not constrained by this at all. Only order-derived entries are.
 *
 * Replaces the standalone `order_id` index rather than sitting beside it: `(order_id, type)`
 * has `order_id` as its leftmost column, so every lookup the old index served is served by
 * this one, and keeping both would mean maintaining two B-trees for one access pattern.
 *
 * ## Surplus rows are removed first, and that is a correction rather than a rewrite
 *
 * A duplicate here is not history. It is the race's output: a second row asserting a payment
 * that happened once. Deleting it makes the ledger say what occurred, and the balances that
 * were computed from it are recomputed below so nothing is left disagreeing. Entries an
 * operator wrote are untouched — they have no `order_id` unless somebody typed one, and the
 * lowest id in each group is always kept.
 *
 * Most installs have none of these and take the fast path straight to the index.
 */
return new class extends Migration
{
    public function up(): void
    {
        $surplus = $this->surplusIds();

        if ($surplus !== []) {
            $members = DB::table('loyalty_transactions')
                ->whereIn('id', $surplus)
                ->distinct()
                ->pluck('member_id')
                ->all();

            DB::table('loyalty_transactions')->whereIn('id', $surplus)->delete();

            Log::warning('Loyalty Points: removed duplicate order entries before adding the uniqueness guard.', [
                'entries' => count($surplus),
                'members' => count($members),
            ]);

            $this->recalculate($members);
        }

        Schema::table('loyalty_transactions', function (Blueprint $table) {
            // Dropped by column name: the create migration made it with Laravel's
            // generated name, and naming it here would guess at that string.
            $table->dropIndex(['order_id']);
            $table->unique(['order_id', 'type'], 'loyalty_tx_order_type_unique');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropUnique('loyalty_tx_order_type_unique');
            $table->index('order_id');
        });
    }

    /**
     * Every id in an `(order_id, type)` group except the earliest.
     *
     * @return array<int,int>
     */
    private function surplusIds(): array
    {
        $groups = DB::table('loyalty_transactions')
            ->select('order_id', 'type', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as total'))
            ->whereNotNull('order_id')
            ->groupBy('order_id', 'type')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        $ids = [];

        foreach ($groups as $group) {
            $ids = array_merge($ids, DB::table('loyalty_transactions')
                ->where('order_id', $group->order_id)
                ->where('type', $group->type)
                ->where('id', '!=', $group->keep_id)
                ->pluck('id')
                ->all());
        }

        return $ids;
    }

    /**
     * Rebuild the affected members' totals, mirroring `LoyaltyMember::recalculate()`.
     *
     * Written in SQL rather than through the model on purpose. A migration runs during
     * `plugin:enable`, and reaching for a plugin class from inside one couples the schema
     * step to the autoloader having been registered first. The rules are restated here
     * because they must not drift: balance is every entry; lifetime is earnings, the
     * operator's own adjustments, and corrections whose target was itself an earning.
     *
     * @param  array<int,int>  $memberIds
     */
    private function recalculate(array $memberIds): void
    {
        foreach ($memberIds as $memberId) {
            $balance = (int) DB::table('loyalty_transactions')
                ->where('member_id', $memberId)
                ->sum('points');

            $lifetime = (int) DB::table('loyalty_transactions as t')
                ->where('t.member_id', $memberId)
                ->where(function ($outer) {
                    $outer
                        ->where(fn ($q) => $q->whereIn('t.type', ['earn', 'adjust'])->whereNull('t.reverses_id'))
                        ->orWhere(fn ($q) => $q->whereNotNull('t.reverses_id')->whereExists(
                            fn ($sub) => $sub->select(DB::raw(1))
                                ->from('loyalty_transactions as o')
                                ->whereColumn('o.id', 't.reverses_id')
                                ->where('o.type', 'earn')
                        ));
                })
                ->sum('t.points');

            $lifetime = max(0, $lifetime);

            $tierId = DB::table('loyalty_tiers')
                ->where('status', 'active')
                ->where('threshold', '<=', $lifetime)
                ->orderByDesc('threshold')
                ->value('id');

            DB::table('loyalty_members')->where('id', $memberId)->update([
                'balance'         => $balance,
                'lifetime_points' => $lifetime,
                'tier_id'         => $tierId,
                'updated_at'      => now(),
            ]);
        }
    }
};

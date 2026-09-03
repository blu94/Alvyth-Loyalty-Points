<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the three point columns one range.
 *
 * `balance` was a signed `integer` and `lifetime_points` an `unsignedInteger` — two numbers
 * derived from the same ledger, with different ceilings. A member could hold a balance their
 * own lifetime total had no room to represent, and the pair would then disagree for a reason
 * no screen could explain.
 *
 * Widened rather than narrowed. Points accumulate and are never compacted; a shop running a
 * 100-points-per-unit rate reaches the old signed ceiling inside a few years of ordinary
 * trade, and the failure mode there is an insert that throws mid-checkout.
 *
 * `balance` stays **signed**. The ledger is the truth and a balance is its sum, so if the sum
 * is ever negative the column must be able to say so — flooring it here would make the
 * screen and the entries disagree and hide the very condition worth seeing. Over-spending is
 * refused at the write instead, in `Ledger` and `SpendPointsOnPaidOrder`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->bigInteger('points')->change();
        });

        Schema::table('loyalty_members', function (Blueprint $table) {
            $table->bigInteger('balance')->default(0)->change();
            $table->unsignedBigInteger('lifetime_points')->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->integer('points')->change();
        });

        Schema::table('loyalty_members', function (Blueprint $table) {
            $table->integer('balance')->default(0)->change();
            $table->unsignedInteger('lifetime_points')->default(0)->change();
        });
    }
};

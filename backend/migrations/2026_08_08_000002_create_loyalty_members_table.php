<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per customer enrolled in the programme.
 *
 * `balance` and `lifetime_points` are **materialised from the ledger, never incremented**.
 * An increment drifts the moment a transaction is edited, deleted, or written twice; a
 * recompute from `SUM(loyalty_transactions.points)` cannot disagree with the ledger
 * because it is the ledger. At admin scale the sum is far too cheap to be worth the risk.
 *
 * They are two different numbers and both are needed:
 * - `balance` is what the customer can still spend — it falls when they redeem.
 * - `lifetime_points` is what they have ever earned — it never falls, which is what makes
 *   tier standing stable. If tiers were driven by balance, redeeming a reward would demote
 *   the customer, which is the opposite of what a loyalty programme is for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_members', function (Blueprint $table) {
            $table->id();

            // Customers are core `users` — Ovynt has no separate customers table, and
            // `orders.user_id` points here too. Cascade: a deleted user's points cannot
            // belong to anyone.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique('user_id');

            $table->integer('balance')->default(0);
            $table->unsignedInteger('lifetime_points')->default(0);

            // Null until they cross the lowest threshold. `nullOnDelete` so deleting a
            // tier demotes its members rather than deleting them.
            $table->foreignId('tier_id')->nullable()->constrained('loyalty_tiers')->nullOnDelete();

            $table->string('status')->default('active');   // active|suspended
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_members');
    }
};

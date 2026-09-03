<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record who wrote a ledger entry.
 *
 * The ledger called itself an audit record and could not answer its first question. An
 * administrator granting themselves 50,000 points through Points Activity left a row with a
 * reason they typed and nothing else — no author, no activity-log trail, nothing to tell a
 * grant from a correction after the fact.
 *
 * **Null means the system wrote it**, and that is not a gap: those entries carry `order_id`,
 * which says exactly which order produced them. A column that had to name somebody would
 * force a fiction for every listener-written row.
 *
 * **Set by the repository, never by the Ledger from `auth()`.** During a storefront checkout
 * the authenticated user is the *customer*, so a blanket `auth()->id()` inside the writer
 * would stamp the buyer as the author of a system entry — a wrong name is worse than no name
 * on an audit trail. Only the admin path knows it is an admin, so only it passes one.
 *
 * `nullOnDelete` rather than cascade: a deleted administrator must not take the entries they
 * made out of the ledger with them. The pointer is expendable; the entry is not — and the
 * activity log written alongside it keeps the name anyway.
 *
 * A new migration rather than an edit to the create: v1.8.0 has shipped, so this table
 * exists on installs that are not ours to rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->foreignId('created_by')
                ->nullable()
                ->after('reverses_id')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
        });
    }
};

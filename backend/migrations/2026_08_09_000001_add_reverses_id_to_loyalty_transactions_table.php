<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Record which entry a correction undoes.
 *
 * A refund writes mirror entries — the earn comes off, the redemption goes back on. Without
 * a way to recognise them, `lifetime_points` (a sum of every positive entry) counted the
 * returned redemption as *earning*, so refunding an order could push a customer into a
 * higher tier. It also never removed the earn it reversed, because that mirror is negative
 * and the sum only looked at positives. Wrong in both directions from one missing fact.
 *
 * A column rather than a reason-string convention. The reversal already wrote a recognisable
 * sentence, and matching on it would make tier standing depend on prose that a translation,
 * a typo or an operator's edit could break.
 *
 * A new migration rather than an edit to the create: v1.2.0 has shipped, so this table exists
 * on installs that are not ours to rewrite.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            // Self-referencing and nullable — most entries reverse nothing.
            //
            // `nullOnDelete` rather than cascade: if the original is ever removed the
            // correction still happened and must remain in the ledger. Losing the pointer
            // is acceptable; losing the entry is not.
            $table->foreignId('reverses_id')
                ->nullable()
                ->after('order_id')
                ->constrained('loyalty_transactions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reverses_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The points ledger — the source of truth for every balance in the programme.
 *
 * `points` is **signed**: earning is positive, redeeming negative. One signed column rather
 * than separate earned/spent tables because a balance is then a single `SUM()` that cannot
 * disagree with itself, and "what happened to my points" is one ordered query.
 *
 * Rows are meant to be append-only in spirit — an operator correcting a mistake should post
 * a compensating `adjust` entry rather than editing history. Editing is still allowed
 * (an operator who fat-fingered 5000 needs a way out) and recomputes the member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_transactions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('member_id')->constrained('loyalty_members')->cascadeOnDelete();

            $table->integer('points');
            $table->string('type')->default('earn');        // earn|redeem|adjust|expire
            $table->string('reason')->nullable();

            // Deliberately NOT a foreign key. A ledger is an audit record: if an order is
            // later deleted, the entry must still say the points came from it. A constraint
            // would either erase that history or block the deletion, and it would also make
            // the plugin's uninstall depend on core table ordering.
            $table->unsignedBigInteger('order_id')->nullable();

            $table->timestamps();

            $table->index(['member_id', 'created_at']);
            $table->index('type');
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_transactions');
    }
};

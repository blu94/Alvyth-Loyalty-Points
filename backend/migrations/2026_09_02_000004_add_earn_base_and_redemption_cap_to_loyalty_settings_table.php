<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the programme pays out on, and how much of one order points may cover.
 *
 * Earning was computed from `grand_total` — tax and shipping included — while redemption was
 * capped by the checkout's `remaining()`, which is subtotal less discounts. Two halves of one
 * programme on two bases, neither of them a choice the operator could make. The base is the
 * half that costs money: it paid points out on tax the shop collects and remits, and on
 * carrier charges it passes straight through.
 *
 * **`grand_total` is the column default, deliberately, even though `merchandise` is the
 * better policy.** This migration runs on shops already running the programme, and silently
 * moving what they pay out on during an upgrade would rewrite their liability without anyone
 * choosing it. The default preserves today's behaviour; the Settings hint recommends the
 * other one and switching is one click.
 *
 * `max_redemption_percent` is nullable and null means uncapped, matching how `expiry_months`
 * already expresses "no policy". A cap is the ordinary abuse control on a points programme —
 * without one a single order can be paid for entirely in points.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            // merchandise|grand_total
            $table->string('earn_base')->default('grand_total')->after('points_per_currency');

            $table->unsignedTinyInteger('max_redemption_percent')->nullable()->after('minimum_redemption');
        });
    }

    public function down(): void
    {
        Schema::table('loyalty_settings', function (Blueprint $table) {
            $table->dropColumn(['earn_base', 'max_redemption_percent']);
        });
    }
};

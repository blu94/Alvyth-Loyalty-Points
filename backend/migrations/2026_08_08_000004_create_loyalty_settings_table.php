<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Programme-wide rules — one row, always id 1.
 *
 * A single-row table rather than a key/value bag because every setting here is typed and
 * validated: an earn rate is a decimal, an expiry is a whole number of months. A key/value
 * store would make all four strings and push the casting into every reader.
 *
 * The row is seeded here rather than created on first save, so `pageData('settings')` has
 * something to bind and the screen never renders empty on a fresh install.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('loyalty_settings', function (Blueprint $table) {
            $table->id();

            // Points earned per 1.00 of order value.
            $table->decimal('points_per_currency', 8, 2)->default(1);

            // What one point is worth when redeemed, in store currency.
            $table->decimal('redeem_value', 8, 4)->default(0.01);

            // Null means points never expire.
            $table->unsignedSmallInteger('expiry_months')->nullable();

            $table->unsignedInteger('minimum_redemption')->default(0);
            $table->timestamps();
        });

        DB::table('loyalty_settings')->insert([
            'points_per_currency' => 1,
            'redeem_value'        => 0.01,
            'expiry_months'       => null,
            'minimum_redemption'  => 0,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('loyalty_settings');
    }
};

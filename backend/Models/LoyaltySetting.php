<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltySetting extends Model
{
    protected $table = 'loyalty_settings';

    protected $fillable = ['points_per_currency', 'redeem_value', 'expiry_months', 'minimum_redemption'];

    protected $casts = [
        'points_per_currency' => 'decimal:2',
        'redeem_value'        => 'decimal:4',
        'expiry_months'       => 'integer',
        'minimum_redemption'  => 'integer',
    ];

    /**
     * The one settings row, created if the seed is missing.
     *
     * `firstOrCreate` rather than `findOrFail(1)`: the migration seeds the row, but a
     * database restored from a partial dump — or a purge that dropped and re-migrated —
     * would otherwise 500 the settings screen instead of showing defaults.
     */
    public static function current(): self
    {
        return static::query()->firstOrCreate([], [
            'points_per_currency' => 1,
            'redeem_value'        => 0.01,
            'expiry_months'       => null,
            'minimum_redemption'  => 0,
        ]);
    }
}

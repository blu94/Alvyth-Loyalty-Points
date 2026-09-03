<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;

class LoyaltySetting extends Model
{
    /**
     * Rate changes are logged.
     *
     * An earn rate moved from 1 to 100 and back left no trace at all, which on the one screen
     * governing what the programme pays out is the change most worth being able to see.
     */
    use LogsSystemActivity;

    /** Earn on the merchandise total, net of discounts — tax and shipping excluded. */
    public const BASE_MERCHANDISE = 'merchandise';

    /** Earn on everything the customer paid, tax and shipping included. */
    public const BASE_GRAND_TOTAL = 'grand_total';

    public const EARN_BASES = [self::BASE_MERCHANDISE, self::BASE_GRAND_TOTAL];

    protected $table = 'loyalty_settings';

    protected $fillable = [
        'points_per_currency', 'earn_base', 'redeem_value',
        'expiry_months', 'minimum_redemption', 'max_redemption_percent',
    ];

    protected $casts = [
        'points_per_currency'    => 'decimal:2',
        'redeem_value'           => 'decimal:4',
        'expiry_months'          => 'integer',
        'minimum_redemption'     => 'integer',
        'max_redemption_percent' => 'integer',
    ];

    /**
     * What one point is worth, in ten-thousandths of a currency unit.
     *
     * The column is `decimal(8,4)`, so ten-thousandths is exactly its resolution and this
     * conversion is lossless. Everything that prices a redemption works in this integer
     * rather than in the decimal, because `floor($value / $rate)` on binary floats charged
     * one point too few for 125 of every 1,000 amounts at a rate of 0.01, and 348 of every
     * 1,000 at 0.05 — measured on the running container, not estimated.
     */
    public function redeemValueTenThousandths(): int
    {
        return (int) round((float) $this->redeem_value * 10000);
    }

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
            'points_per_currency'    => 1,
            'earn_base'              => self::BASE_GRAND_TOTAL,
            'redeem_value'           => 0.01,
            'expiry_months'          => null,
            'minimum_redemption'     => 0,
            'max_redemption_percent' => null,
        ]);
    }
}

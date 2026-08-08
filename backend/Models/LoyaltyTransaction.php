<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    public const TYPE_EARN   = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_EXPIRE = 'expire';

    protected $table = 'loyalty_transactions';

    protected $fillable = ['member_id', 'points', 'type', 'reason', 'order_id'];

    protected $casts = [
        'points' => 'integer',
    ];

    public function member()
    {
        return $this->belongsTo(LoyaltyMember::class, 'member_id');
    }

    /**
     * The signed value to store for a typed entry.
     *
     * An operator typing "100" against **Redeem** means "take 100 away" — nobody types a
     * minus sign for a redemption, and a form that silently credits them instead is a bug
     * they have no way to see. So earn is forced positive and redeem/expire negative.
     *
     * **Adjust keeps the sign as typed**, because that is the entire point of an
     * adjustment: it is the one entry an operator uses to correct in either direction.
     *
     * A static taking both values rather than a `setPointsAttribute` mutator: a mutator
     * would fire while `type` may not have been assigned yet — mass assignment applies
     * attributes in payload order — so the rule would depend on key order in a JSON body.
     */
    public static function signedPoints(string $type, mixed $points): int
    {
        $points = (int) $points;

        return match ($type) {
            self::TYPE_EARN                   => abs($points),
            self::TYPE_REDEEM, self::TYPE_EXPIRE => -abs($points),
            default                           => $points,
        };
    }
}

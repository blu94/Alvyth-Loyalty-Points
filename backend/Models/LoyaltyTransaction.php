<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use App\Models\User;
use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTransaction extends Model
{
    /**
     * Every write is logged to the audit trail.
     *
     * Core's own trait, not a local one: it is already wired to the Activity Log screen and
     * to the per-user Activity tab, so an entry made here is findable in the same place as
     * every other change an operator makes. A points ledger is a liability record, and the
     * first property of one is being able to say who moved a number.
     *
     * Safe across an uninstall. The log stores this class name as a string and core's
     * `resolveSubject()` already degrades gracefully when a plugin's models are gone.
     */
    use LogsSystemActivity;

    public const TYPE_EARN   = 'earn';
    public const TYPE_REDEEM = 'redeem';
    public const TYPE_ADJUST = 'adjust';
    public const TYPE_EXPIRE = 'expire';

    protected $table = 'loyalty_transactions';

    protected $fillable = ['member_id', 'points', 'type', 'reason', 'order_id', 'reverses_id', 'created_by'];

    protected $casts = [
        'points' => 'integer',
    ];

    public function member()
    {
        return $this->belongsTo(LoyaltyMember::class, 'member_id');
    }

    /**
     * The administrator who wrote this entry, or null when the programme did.
     *
     * Null is the ordinary case and not a gap: a listener-written entry carries `order_id`,
     * which says precisely which order produced it. Only an operator's own entry names a
     * person, because only that one was somebody's decision.
     */
    public function author()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The entry this one undoes, if it is a correction.
     *
     * What makes a reversal recognisable to `LoyaltyMember::recalculate()` without reading
     * its reason: restoring a spent redemption must not count as earning, and undoing an
     * earn must. Only the original can say which of the two this is.
     */
    public function reverses()
    {
        return $this->belongsTo(self::class, 'reverses_id');
    }

    /**
     * The corrections that undo this entry, if any.
     *
     * The other side of `reverses()`, and what lets a caller ask "has this already been
     * undone?" without scanning by reason text. Both the refund listener and the expiry
     * undo need that question answered before writing a second mirror, and answering it
     * from the pointer is what keeps them from disagreeing with `recalculate()`.
     */
    public function reversals()
    {
        return $this->hasMany(self::class, 'reverses_id');
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
     *
     * **Applied by the repository, not by the model.** Writing through
     * `LoyaltyTransaction::create()` stores exactly what it is given, so a listener building
     * an entry by hand has to call this itself — passing a positive number with
     * `type = redeem` would raise the balance it meant to lower.
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

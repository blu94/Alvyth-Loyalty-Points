<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class LoyaltyMember extends Model
{
    protected $table = 'loyalty_members';

    protected $fillable = ['user_id', 'balance', 'lifetime_points', 'tier_id', 'status', 'joined_at'];

    protected $casts = [
        'balance'         => 'integer',
        'lifetime_points' => 'integer',
        'joined_at'       => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function tier()
    {
        return $this->belongsTo(LoyaltyTier::class, 'tier_id');
    }

    public function transactions()
    {
        return $this->hasMany(LoyaltyTransaction::class, 'member_id');
    }

    /**
     * Rebuild this member's totals and tier from their ledger.
     *
     * **Recomputed, not incremented.** Both callers already know the delta they just wrote,
     * so adding it would be faster — and would drift the first time a transaction is edited,
     * deleted, or written twice by a retried request. Deriving from `SUM()` makes those cases
     * self-healing instead: whatever the ledger says is what the member is.
     *
     * `lifetime_points` counts only positive entries, so redeeming never costs tier standing.
     */
    public function recalculate(): self
    {
        $this->balance         = (int) $this->transactions()->sum('points');
        $this->lifetime_points = (int) $this->transactions()->where('points', '>', 0)->sum('points');
        $this->tier_id         = $this->qualifyingTierId();

        $this->save();

        return $this;
    }

    /**
     * The highest tier this member's lifetime points reach, or null if none.
     *
     * Only `active` tiers qualify: switching a tier off must stop it being awarded without
     * having to delete it and lose its definition.
     */
    private function qualifyingTierId(): ?int
    {
        return LoyaltyTier::query()
            ->where('status', 'active')
            ->where('threshold', '<=', $this->lifetime_points)
            ->orderByDesc('threshold')
            ->value('id');
    }
}

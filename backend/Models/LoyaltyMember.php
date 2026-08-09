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
     * **Balance is every entry; lifetime is only what was earned.** Spending must not cost
     * standing — that is why the two numbers exist — but a *correction* to an earning has to
     * count, or the ledger and the tier disagree.
     */
    public function recalculate(): self
    {
        $this->balance = (int) $this->transactions()->sum('points');

        // Lifetime = earnings, plus corrections to earnings, and nothing else.
        //
        // It used to be "every positive entry", which was wrong in both directions once
        // refunds existed. Reversing an earn writes a NEGATIVE mirror, which a positives-only
        // sum ignored, so lifetime never fell for a purchase that was undone. Reversing a
        // redemption writes a POSITIVE mirror, which that sum counted as earning — so a
        // refund could push a customer *up* a tier. One rule fixed both: the sum is signed,
        // and it takes only the entries that represent earning.
        //
        // A reversal is identified by `reverses_id`, not by reading its reason. Tier standing
        // must not depend on prose that a translation or an edit could change.
        // The two alternatives are wrapped in ONE outer group on purpose. Written as
        // `->where(A)->orWhere(B)` the relation's own `member_id = ?` binds only to the first
        // branch — SQL reads `(member_id = ? AND A) OR B` — so B matched reversals belonging
        // to *every other member in the shop*. One customer's refund then rewrote everybody
        // else's standing: a stranger's -5,000 mirror summed into a member with 1,200 earned
        // and floored their lifetime to zero, demoting them out of their tier.
        $earned = $this->transactions()
            ->where(function ($scoped) {
                $scoped
                    ->where(function ($q) {
                        // Earnings themselves, and the operator's manual grants and deductions
                        // — `adjust` is the only type whose sign is the author's intent.
                        $q->whereIn('type', [LoyaltyTransaction::TYPE_EARN, LoyaltyTransaction::TYPE_ADJUST])
                            ->whereNull('reverses_id');
                    })
                    ->orWhere(function ($q) {
                        // A correction, but only where what it undoes was itself an earning.
                        // The mirror of a redemption restores spendable balance; it is not new
                        // earning and must leave standing untouched.
                        $q->whereNotNull('reverses_id')
                            ->whereHas('reverses', fn ($r) => $r->where('type', LoyaltyTransaction::TYPE_EARN));
                    });
            })
            ->sum('points');

        // Floored at zero. More reversed than earned is arithmetically possible after enough
        // corrections, and a negative lifetime would be meaningless to compare a threshold
        // against — the honest reading is "has earned nothing that still stands".
        $this->lifetime_points = max(0, (int) $earned);

        $this->tier_id = $this->qualifyingTierId();

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

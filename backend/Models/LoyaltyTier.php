<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use App\Traits\LogsSystemActivity;
use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
    /** A threshold edit re-ranks every member, so it is worth being able to see who made it. */
    use LogsSystemActivity;

    protected $table = 'loyalty_tiers';

    protected $fillable = ['title', 'threshold', 'status'];

    protected $casts = [
        'threshold' => 'integer',
    ];

    public function members()
    {
        return $this->hasMany(LoyaltyMember::class, 'tier_id');
    }
}

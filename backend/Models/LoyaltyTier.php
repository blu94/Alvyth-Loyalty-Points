<?php

namespace Plugin\LoyaltyPoints\Backend\Models;

use Illuminate\Database\Eloquent\Model;

class LoyaltyTier extends Model
{
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

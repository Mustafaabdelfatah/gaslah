<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A customer's loyalty points account — one per customer.
 *
 * points_balance is the redeemable balance and is only ever written under a row lock so
 * two concurrent redemptions cannot mint value from the same points twice.
 */
class LoyaltyAccount extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'program_id',
        'tier_id',
        'points_balance',
        'lifetime_points',
    ];

    protected $casts = [
        'points_balance' => 'decimal:2',
        'lifetime_points' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(LoyaltyProgram::class, 'program_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class, 'account_id');
    }
}

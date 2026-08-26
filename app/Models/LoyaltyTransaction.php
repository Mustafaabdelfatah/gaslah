<?php

namespace App\Models;

use App\Enum\Loyalty\LoyaltyTransactionTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A single loyalty points movement.
 */
class LoyaltyTransaction extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'type',
        'points',
        'order_id',
        'note',
        'expires_at',
    ];

    protected $casts = [
        'type' => LoyaltyTransactionTypeEnum::class,
        'points' => 'decimal:2',
        'expires_at' => 'datetime',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(LoyaltyAccount::class, 'account_id');
    }
}

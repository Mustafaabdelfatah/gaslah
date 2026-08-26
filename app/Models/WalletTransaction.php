<?php

namespace App\Models;

use App\Enum\Payments\WalletTransactionTypeEnum;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One wallet ledger row. balance_after is written under the same lock that moved the
 * balance, so the two never diverge.
 */
class WalletTransaction extends BaseModel
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'legacy_cuid',
        'customer_id',
        'type',
        'amount',
        'balance_after',
        'ref_id',
        'note',
    ];

    protected $casts = [
        'type' => WalletTransactionTypeEnum::class,
        'amount' => 'decimal:2',
        'balance_after' => 'decimal:2',
        'created_at' => 'datetime',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}

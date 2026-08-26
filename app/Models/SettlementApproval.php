<?php

namespace App\Models;

use App\Enum\Payments\SettlementDecisionEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin vote on a settlement (append-only, one per admin per settlement).
 */
class SettlementApproval extends BaseModel
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'settlement_id',
        'admin_id',
        'decision',
        'note',
    ];

    protected $casts = [
        'decision' => SettlementDecisionEnum::class,
        'created_at' => 'datetime',
    ];

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(PayoutSettlement::class, 'settlement_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }
}

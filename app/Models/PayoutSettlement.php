<?php

namespace App\Models;

use App\Enum\Payments\SettlementStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A bank payout settlement — gateway funds the platform holds for an organization,
 * batched and transferred after maker-checker approval.
 */
class PayoutSettlement extends BaseModel
{
    protected $fillable = [
        'organization_id',
        'status',
        'urgent',
        'period_start',
        'period_end',
        'payment_count',
        'gross_amount',
        'fee_amount',
        'net_amount',
        'currency',
        'bank_snapshot',
        'requested_by_id',
        'created_by_id',
        'approved_at',
        'sent_by_id',
        'sent_at',
        'transfer_ref',
        'note',
        'rejected_reason',
    ];

    protected $casts = [
        'status' => SettlementStatusEnum::class,
        'urgent' => 'boolean',
        'period_start' => 'datetime',
        'period_end' => 'datetime',
        'payment_count' => 'integer',
        'gross_amount' => 'decimal:2',
        'fee_amount' => 'decimal:2',
        'net_amount' => 'decimal:2',
        'bank_snapshot' => 'array',
        'approved_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', SettlementStatusEnum::openValues());
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(SettlementApproval::class, 'settlement_id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class, 'settlement_id');
    }
}

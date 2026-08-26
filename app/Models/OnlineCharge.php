<?php

namespace App\Models;

use App\Enum\Payments\OnlineChargePurposeEnum;
use App\Enum\Payments\OnlineChargeStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The mirror of a gateway transaction. One row per provider charge; the platform
 * payments monitor reads these exclusively.
 */
class OnlineCharge extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'provider',
        'provider_ref',
        'purpose',
        'order_id',
        'customer_id',
        'subscription_id',
        'amount',
        'currency',
        'status',
        'idempotency_key',
        'raw_status',
    ];

    protected $casts = [
        'purpose' => OnlineChargePurposeEnum::class,
        'status' => OnlineChargeStatusEnum::class,
        'amount' => 'decimal:2',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}

<?php

namespace App\Models;

use App\Enum\Market\MarketCommissionTypeEnum;
use App\Enum\Market\MarketOrderStatusEnum;
use App\Enum\Market\MarketPaymentMethodEnum;
use App\Enum\Market\MarketPaymentStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laundry's purchase from one market supplier.
 *
 * The commission figures are a snapshot from creation, never recomputed: renegotiating a
 * rate must not rewrite what the platform already earned or what a supplier was already
 * promised.
 */
class MarketOrder extends BaseModel
{
    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'supplier_id',
        'status',
        'payment_method',
        'payment_status',
        'subtotal',
        'commission_type',
        'commission_rate',
        'commission_amount',
        'total',
        'supplier_payout',
        'address',
        'notes',
        'created_by_id',
        'delivered_at',
    ];

    protected $casts = [
        'status' => MarketOrderStatusEnum::class,
        'payment_method' => MarketPaymentMethodEnum::class,
        'payment_status' => MarketPaymentStatusEnum::class,
        'commission_type' => MarketCommissionTypeEnum::class,
        'subtotal' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'total' => 'decimal:2',
        'supplier_payout' => 'decimal:2',
        'delivered_at' => 'datetime',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeForSupplier(Builder $query, int $supplierId): Builder
    {
        return $query->where('supplier_id', $supplierId);
    }

    /**
     * Orders that have actually earned the supplier their money.
     */
    public function scopeDelivered(Builder $query): Builder
    {
        return $query->where('status', MarketOrderStatusEnum::Delivered->value);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(MarketSupplier::class, 'supplier_id');
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketOrderItem::class, 'order_id');
    }
}

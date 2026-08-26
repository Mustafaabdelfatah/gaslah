<?php

namespace App\Models;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One delivery trip — a pickup (customer → facility) or a delivery (facility →
 * customer). Its status history is the audit trail of every transition.
 */
class DeliveryRequest extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'customer_id',
        'order_id',
        'driver_id',
        'zone_id',
        'type',
        'status',
        'fee',
        'fee_applied_to_order',
        'address',
        'notes',
        'scheduled_at',
        'source',
        'created_by_id',
        'assigned_at',
        'completed_at',
        'accepted_at',
        'rejected_at',
        'reject_reason',
        'arrived_at',
        'pickup_photo_url',
        'delivery_photo_url',
        'inventory_done_at',
        'inventory_notes',
        'invoice_approval_required',
        'invoice_approved_at',
        'lat',
        'lng',
        'external_provider',
        'external_ref',
    ];

    protected $casts = [
        'type' => DeliveryTypeEnum::class,
        'status' => DeliveryStatusEnum::class,
        'source' => DeliverySourceEnum::class,
        'fee' => 'decimal:2',
        'fee_applied_to_order' => 'boolean',
        'invoice_approval_required' => 'boolean',
        'scheduled_at' => 'datetime',
        'assigned_at' => 'datetime',
        'completed_at' => 'datetime',
        'accepted_at' => 'datetime',
        'rejected_at' => 'datetime',
        'arrived_at' => 'datetime',
        'inventory_done_at' => 'datetime',
        'invoice_approved_at' => 'datetime',
        'lat' => 'decimal:7',
        'lng' => 'decimal:7',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    /**
     * @param  array<int, int>  $branchIds
     */
    public function scopeInBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn('branch_id', $branchIds);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function zone(): BelongsTo
    {
        return $this->belongsTo(DeliveryZone::class, 'zone_id');
    }

    public function history(): HasMany
    {
        return $this->hasMany(DeliveryStatusHistory::class, 'request_id');
    }
}

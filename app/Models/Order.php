<?php

namespace App\Models;

use App\Enum\Orders\OrderPriorityEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A laundry order from creation through to delivery and archival.
 *
 * Totals are a server-computed snapshot, and the tax rate is captured at creation for
 * ZATCA compliance rather than read live later.
 */
class Order extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'branch_id',
        'customer_id',
        'cashier_id',
        'subscription_id',
        'order_no',
        'barcode',
        'status',
        'priority',
        'payment_status',
        'due_at',
        'notes',
        'subtotal',
        'discount_total',
        'tax_total',
        'tax_rate',
        'grand_total',
        'paid_total',
        'delivery_fee',
        'client_request_id',
        'delivered_at',
        'archived_at',
        'metadata',
    ];

    protected $casts = [
        'status' => OrderStatusEnum::class,
        'priority' => OrderPriorityEnum::class,
        'payment_status' => PaymentStatusEnum::class,
        'subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'tax_total' => 'decimal:2',
        'tax_rate' => 'decimal:2',
        'grand_total' => 'decimal:2',
        'paid_total' => 'decimal:2',
        'delivery_fee' => 'decimal:2',
        'due_at' => 'datetime',
        'delivered_at' => 'datetime',
        'archived_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * The outstanding amount on the order.
     */
    public function remaining(): float
    {
        return round((float) $this->grand_total - (float) $this->paid_total, 2);
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class);
    }
}

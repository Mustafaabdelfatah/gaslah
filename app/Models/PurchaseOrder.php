<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A purchase order — read-only in this API.
 */
class PurchaseOrder extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'supplier_id',
        'status',
        'total',
        'received_at',
    ];

    protected $casts = [
        'total' => 'decimal:2',
        'received_at' => 'datetime',
    ];

    /**
     * @param  array<int, int>  $branchIds
     */
    public function scopeInBranches(Builder $query, array $branchIds): Builder
    {
        return $query->whereIn('branch_id', $branchIds);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }
}

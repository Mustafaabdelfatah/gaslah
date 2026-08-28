<?php

namespace App\Models;

use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A manual stock item. `lowStock` is computed (quantity <= reorder_level), never stored.
 */
class InventoryItem extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'unit_id',
        'name',
        'sku',
        'cost',
        'quantity',
        'reorder_level',
        'is_active',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'quantity' => 'decimal:2',
        'reorder_level' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    protected $appends = ['low_stock'];

    /**
     * Whether the item is at or below its reorder level.
     */
    public function getLowStockAttribute(): bool
    {
        return (float) $this->quantity <= (float) $this->reorder_level;
    }

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

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->whereColumn('quantity', '<=', 'reorder_level');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

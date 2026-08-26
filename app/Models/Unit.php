<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A unit of measure, scoped to an organization.
 */
class Unit extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'name',
        'symbol',
        'conversion_factor',
        'is_active',
    ];

    protected $casts = [
        'conversion_factor' => 'decimal:4',
        'is_active' => 'boolean',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InventoryItem::class);
    }
}

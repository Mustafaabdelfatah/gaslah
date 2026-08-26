<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A geographic delivery zone with a fixed fee that overrides self-delivery pricing.
 */
class DeliveryZone extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'name_en',
        'fee',
        'postal_codes',
        'eta_minutes',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'fee' => 'decimal:2',
        'postal_codes' => 'array',
        'eta_minutes' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
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

    public function requests(): HasMany
    {
        return $this->hasMany(DeliveryRequest::class, 'zone_id');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GarmentType extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'organization_id',
        'name',
        'name_en',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

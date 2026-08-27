<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A founding partner of the platform: an ownership stake and a claim on its profit.
 */
class PlatformPartner extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name',
        'role',
        'email',
        'ownership_percent',
        'joined_at',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'ownership_percent' => 'decimal:2',
        'joined_at' => 'date',
        'is_active' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * An inactive partner contributes nothing: they neither accrue a share nor consume
     * part of the ownership ceiling.
     */
    public function effectiveOwnership(): float
    {
        return $this->is_active ? (float) $this->ownership_percent : 0.0;
    }

    public function distributions(): HasMany
    {
        return $this->hasMany(PlatformPartnerDistribution::class, 'partner_id');
    }
}

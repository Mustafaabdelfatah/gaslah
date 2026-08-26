<?php

namespace App\Models;

use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An organization's loyalty programme — one per organization.
 *
 * When none is saved a defaults template is returned for the settings form; it carries
 * exists = false, and edits/redemptions are refused until it is actually saved.
 */
class LoyaltyProgram extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'organization_id',
        'name',
        'earn_rate',
        'point_value',
        'expiry_months',
        'is_active',
    ];

    protected $casts = [
        'earn_rate' => 'decimal:2',
        'point_value' => 'decimal:4',
        'expiry_months' => 'integer',
        'is_active' => 'boolean',
    ];

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}

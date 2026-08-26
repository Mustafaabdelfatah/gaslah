<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A delivery driver — an organization's own driver, or a shared platform driver.
 *
 * The phone is unique system-wide so it resolves a single driver at OTP login. A driver
 * authenticates on its own Sanctum surface (kind = driver), separate from staff.
 */
class Driver extends BaseModel implements Authenticatable
{
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    protected $fillable = [
        'organization_id',
        'branch_id',
        'name',
        'phone',
        'user_id',
        'vehicle',
        'is_active',
        'notes',
        'is_platform',
        'coverage_region',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_platform' => 'boolean',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requests(): HasMany
    {
        return $this->hasMany(DeliveryRequest::class);
    }
}

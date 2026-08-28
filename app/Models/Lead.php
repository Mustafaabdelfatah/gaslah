<?php

namespace App\Models;

use App\Enum\Crm\LeadStageEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prospective laundry in the operator's sales pipeline.
 */
class Lead extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'legacy_cuid',
        'business_name',
        'contact_name',
        'phone',
        'email',
        'city',
        'source',
        'stage',
        'expected_mrr',
        'owner_id',
        'converted_organization_id',
        'lost_reason',
        'won_at',
    ];

    protected $casts = [
        'stage' => LeadStageEnum::class,
        'expected_mrr' => 'decimal:2',
        'won_at' => 'datetime',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */

    /**
     * Leads still being worked. Won and lost are the two ends of the pipeline.
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('stage', LeadStageEnum::openValues());
    }

    /**
     * Whether this lead has already become a tenant, which is what refuses a second
     * conversion.
     */
    public function isConverted(): bool
    {
        return $this->converted_organization_id !== null;
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function convertedOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'converted_organization_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(CrmNote::class);
    }
}

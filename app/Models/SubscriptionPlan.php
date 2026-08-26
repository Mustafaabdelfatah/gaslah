<?php

namespace App\Models;

use App\Enum\Subscriptions\SubscriptionCycleEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
use App\Trait\Global\LogsActivityOptions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A prepaid laundry package a business sells to its customers.
 *
 * The plan is the product definition; a customer's own copy of it is a Subscription.
 */
class SubscriptionPlan extends BaseModel
{
    use HasFactory, LogsActivityOptions;

    protected $fillable = [
        'organization_id',
        'name',
        'cycle',
        'type',
        'price',
        'quota',
        'service_id',
        'auto_renew',
        'is_active',
    ];

    protected $casts = [
        'cycle' => SubscriptionCycleEnum::class,
        'type' => SubscriptionTypeEnum::class,
        'price' => 'decimal:2',
        'quota' => 'decimal:2',
        'auto_renew' => 'boolean',
        'is_active' => 'boolean',
    ];

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
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

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'plan_id');
    }
}

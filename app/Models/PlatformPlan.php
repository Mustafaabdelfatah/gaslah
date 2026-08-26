<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A subscription plan the platform sells to organizations (distinct from SubscriptionPlan,
 * which is a customer package inside an organization).
 */
class PlatformPlan extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'name', 'name_en', 'monthly_price', 'yearly_price', 'max_branches', 'max_users',
        'features', 'feature_keys', 'is_popular', 'sort_order', 'is_active',
    ];

    protected $casts = [
        'monthly_price' => 'decimal:2',
        'yearly_price' => 'decimal:2',
        'max_branches' => 'integer',
        'max_users' => 'integer',
        'features' => 'array',
        'feature_keys' => 'array',
        'is_popular' => 'boolean',
        'sort_order' => 'integer',
        'is_active' => 'boolean',
    ];

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class, 'plan_id');
    }
}

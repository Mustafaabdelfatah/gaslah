<?php

namespace App\Models;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

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

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Attach the commercial figures the console listing shows: total and active
     * subscriber counts, and the plan's MRR.
     *
     * MRR counts active subscriptions only, a yearly one at price/12. It is summed in a
     * correlated subquery so a page of plans costs one query rather than one per plan.
     */
    public function scopeWithCommercials(Builder $query): Builder
    {
        $active = PlatformSubscriptionStatusEnum::Active->value;
        $yearly = PlatformCycleEnum::Yearly->value;

        return $query
            ->withCount([
                'subscriptions',
                'subscriptions as active_count' => fn (Builder $q) => $q->where('status', $active),
            ])
            ->withSum(
                ['subscriptions as mrr' => fn (Builder $q) => $q->where('status', $active)],
                DB::raw("CASE WHEN cycle = '{$yearly}' THEN price / 12 ELSE price END"),
            );
    }

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlatformSubscription::class, 'plan_id');
    }
}

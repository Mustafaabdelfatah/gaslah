<?php

namespace App\Models;

use App\Enum\Subscriptions\SubscriptionStatusEnum;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A customer's own copy of a subscription plan.
 *
 * The remaining_quota / remaining_balance columns hold prepaid value the customer draws
 * down at the point of sale; both are written only under a row lock so two concurrent
 * consumptions can never overspend the same subscription.
 */
class Subscription extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'organization_id',
        'customer_id',
        'plan_id',
        'branch_id',
        'start_at',
        'end_at',
        'status',
        'remaining_quota',
        'remaining_balance',
        'auto_renew',
    ];

    protected $casts = [
        'status' => SubscriptionStatusEnum::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'remaining_quota' => 'decimal:2',
        'remaining_balance' => 'decimal:2',
        'auto_renew' => 'boolean',
    ];

    /**
     * Whether the subscription is still within its paid period.
     */
    public function isWithinPeriod(): bool
    {
        return $this->end_at === null || $this->end_at->isFuture() || $this->end_at->equalTo(Carbon::now());
    }

    /*
    |--------------------------------------------------------------------------
    | Scopes methods
    |--------------------------------------------------------------------------
    */
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

    /*
    |--------------------------------------------------------------------------
    | Relations methods
    |--------------------------------------------------------------------------
    */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'plan_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

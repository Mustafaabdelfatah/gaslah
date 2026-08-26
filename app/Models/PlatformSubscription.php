<?php

namespace App\Models;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An organization's subscription to the platform. One row per organization.
 */
class PlatformSubscription extends BaseModel
{
    protected $fillable = [
        'organization_id', 'plan_id', 'cycle', 'status', 'price',
        'started_at', 'current_period_end', 'cancel_at_period_end', 'canceled_at',
    ];

    protected $casts = [
        'cycle' => PlatformCycleEnum::class,
        'status' => PlatformSubscriptionStatusEnum::class,
        'price' => 'decimal:2',
        'started_at' => 'datetime',
        'current_period_end' => 'datetime',
        'cancel_at_period_end' => 'boolean',
        'canceled_at' => 'datetime',
    ];

    /**
     * Whether the subscription is currently within its paid period.
     */
    public function isWithinPeriod(): bool
    {
        return $this->current_period_end === null || $this->current_period_end->isFuture() || $this->current_period_end->equalTo(Carbon::now());
    }

    /**
     * Whether the subscription is live (writable status AND within its period).
     */
    public function isLive(): bool
    {
        return $this->status->isWritable() && $this->isWithinPeriod();
    }

    /**
     * The display status, deriving EXPIRED from a lapsed writable period.
     */
    public function displayStatus(): string
    {
        if ($this->status->isWritable() && ! $this->isWithinPeriod()) {
            return 'expired';
        }

        return $this->status->value;
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'plan_id');
    }
}

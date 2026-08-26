<?php

namespace App\Services\Platform;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformEventTypeEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use Illuminate\Support\Carbon;

/**
 * Manages an organization's platform subscription (one row per org) and records the
 * lifecycle events that feed the MRR waterfall.
 */
class PlatformSubscriptionService
{
    private const DEFAULT_TRIAL_DAYS = 14;

    /**
     * Set (create or update) an organization's subscription.
     */
    public function set(
        Organization $organization,
        PlatformPlan $plan,
        PlatformSubscriptionStatusEnum $status,
        PlatformCycleEnum $cycle,
        ?Carbon $currentPeriodEnd = null,
        bool $cancelAtPeriodEnd = false,
        ?float $customPrice = null,
    ): PlatformSubscription {
        $existing = $organization->platformSubscription;

        $price = $status === PlatformSubscriptionStatusEnum::Trial
            ? 0.0
            : round($customPrice ?? $this->planPrice($plan, $cycle), 2);

        $periodEnd = $currentPeriodEnd ?? Carbon::now()->addMonths($cycle->months());

        $subscription = PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'plan_id' => $plan->getKey(),
                'cycle' => $cycle->value,
                'status' => $status->value,
                'price' => $price,
                'started_at' => $existing?->started_at ?? Carbon::now(),
                'current_period_end' => $periodEnd,
                'cancel_at_period_end' => $cancelAtPeriodEnd,
            ],
        );

        $this->recordEvent(
            $organization,
            $existing === null ? PlatformEventTypeEnum::Signup : PlatformEventTypeEnum::PlanChange,
            $plan,
            $cycle,
            $status === PlatformSubscriptionStatusEnum::Active ? $this->monthly($plan, $cycle, $price) : 0,
        );

        return $subscription;
    }

    /**
     * Start (or restart) a free trial on the cheapest active plan (or a chosen one).
     */
    public function startTrial(Organization $organization, ?PlatformPlan $plan = null): PlatformSubscription
    {
        $plan ??= PlatformPlan::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();

        $subscription = PlatformSubscription::query()->updateOrCreate(
            ['organization_id' => $organization->getKey()],
            [
                'plan_id' => $plan->getKey(),
                'cycle' => PlatformCycleEnum::Monthly->value,
                'status' => PlatformSubscriptionStatusEnum::Trial->value,
                'price' => 0,
                'started_at' => Carbon::now(),
                'current_period_end' => Carbon::now()->addDays($this->trialDays()),
                'cancel_at_period_end' => false,
            ],
        );

        $this->recordEvent($organization, PlatformEventTypeEnum::TrialStart, $plan, PlatformCycleEnum::Monthly, 0);

        return $subscription;
    }

    /**
     * Grant more subscription time; a past-due subscription returns to active.
     */
    public function extend(PlatformSubscription $subscription, int $days): PlatformSubscription
    {
        $base = $subscription->current_period_end && $subscription->current_period_end->isFuture()
            ? $subscription->current_period_end
            : Carbon::now();

        $subscription->forceFill([
            'current_period_end' => $base->copy()->addDays($days),
            'status' => $subscription->status === PlatformSubscriptionStatusEnum::PastDue
                ? PlatformSubscriptionStatusEnum::Active->value
                : $subscription->status->value,
        ])->save();

        $this->recordEvent($subscription->organization, PlatformEventTypeEnum::Extend, $subscription->plan, $subscription->cycle, 0);

        return $subscription->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function planPrice(PlatformPlan $plan, PlatformCycleEnum $cycle): float
    {
        return (float) ($cycle === PlatformCycleEnum::Yearly ? $plan->yearly_price : $plan->monthly_price);
    }

    private function monthly(PlatformPlan $plan, PlatformCycleEnum $cycle, float $price): float
    {
        return round($cycle === PlatformCycleEnum::Yearly ? $price / 12 : $price, 2);
    }

    private function recordEvent(?Organization $organization, PlatformEventTypeEnum $type, ?PlatformPlan $plan, PlatformCycleEnum $cycle, float $monthly): void
    {
        PlatformEvent::query()->create([
            'organization_id' => $organization?->getKey(),
            'type' => $type->value,
            'plan_name' => $plan?->name,
            'cycle' => $cycle->value,
            'monthly' => $monthly,
            'created_at' => Carbon::now(),
        ]);
    }

    private function trialDays(): int
    {
        return (int) config('services.platform.trial_days', self::DEFAULT_TRIAL_DAYS);
    }
}

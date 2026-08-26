<?php

namespace App\Http\Controllers\API\Tenancy\Platform;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\PlatformPlan;
use Illuminate\Http\JsonResponse;

/**
 * The organization's view of its own entitlements and subscription.
 */
class OrgSubscriptionController extends TenantController
{
    /**
     * An entitlements snapshot — any staff may read it (drives feature gating + the status
     * banner).
     */
    public function entitlements(): JsonResponse
    {
        $organization = $this->organization();
        $subscription = $organization->platformSubscription;

        return successResponse([
            ...$this->entitlements->snapshot($organization),
            'read_only' => ! $this->entitlements->isActive($organization),
            'suspended' => (bool) $organization->is_suspended,
            'status' => $subscription?->displayStatus() ?? 'grandfathered',
            'plan_name' => $subscription?->plan?->name,
            'current_period_end' => $subscription?->current_period_end,
            'trial' => $subscription?->status->value === 'trial',
        ]);
    }

    /**
     * The full subscription view — manager only.
     */
    public function subscription(): JsonResponse
    {
        $this->requireManager();

        $subscription = $this->organization()->platformSubscription?->load('plan');

        return successResponse([
            'subscription' => $subscription === null ? null : [
                ...$subscription->toArray(),
                'display_status' => $subscription->displayStatus(),
            ],
            'plans' => PlatformPlan::query()->where('is_active', true)->orderBy('sort_order')->get(),
        ]);
    }
}

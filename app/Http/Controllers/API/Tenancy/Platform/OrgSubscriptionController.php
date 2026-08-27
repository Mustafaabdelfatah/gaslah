<?php

namespace App\Http\Controllers\API\Tenancy\Platform;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Resources\Platform\OrgEntitlementsResource;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Http\Resources\Platform\PlatformSubscriptionResource;
use App\Models\PlatformPlan;
use Illuminate\Http\JsonResponse;

/**
 * The organization's view of its own entitlements and subscription.
 */
class OrgSubscriptionController extends TenantController
{
    /**
     * The entitlements snapshot — readable by any staff member, since it drives feature
     * gating and the account-status banner.
     */
    public function entitlements(): JsonResponse
    {
        $organization = $this->organization();
        $organization->loadMissing('platformSubscription.plan');

        return successResponse(
            new OrgEntitlementsResource($organization, $this->entitlements->snapshot($organization)),
        );
    }

    /**
     * The subscription in full, with the plans available to move to — manager only.
     */
    public function subscription(): JsonResponse
    {

        $subscription = $this->organization()->platformSubscription?->load('plan');

        return successResponse([
            'subscription' => $subscription === null ? null : new PlatformSubscriptionResource($subscription),
            'plans' => PlatformPlanResource::collection(
                PlatformPlan::query()->active()->orderBy('sort_order')->get(),
            ),
        ]);
    }
}

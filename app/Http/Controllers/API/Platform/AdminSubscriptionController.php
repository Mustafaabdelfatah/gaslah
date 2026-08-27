<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Platform\ExtendSubscriptionRequest;
use App\Http\Requests\Platform\SetSubscriptionRequest;
use App\Http\Requests\Platform\StartTrialRequest;
use App\Http\Resources\Platform\PlatformSubscriptionResource;
use App\Models\Organization;
use App\Services\Platform\PlatformSubscriptionService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Platform-admin control of an organization's subscription. Gated on
 * manage_subscriptions at the routes: granting subscription time is a billing decision,
 * not something the manage_tenants that support holds should reach.
 */
class AdminSubscriptionController extends BaseController
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    public function update(SetSubscriptionRequest $request, Organization $organization): JsonResponse
    {
        $subscription = $this->subscriptions->apply($organization, $request);

        return successResponse(
            new PlatformSubscriptionResource($subscription->load('plan')),
            __('api.updated_success'),
        );
    }

    public function startTrial(StartTrialRequest $request, Organization $organization): JsonResponse
    {
        $subscription = $this->subscriptions->startTrial($organization, $request->plan());

        return successResponse(
            new PlatformSubscriptionResource($subscription->load('plan')),
            __('api.updated_success'),
        );
    }

    public function extend(ExtendSubscriptionRequest $request, Organization $organization): JsonResponse
    {
        $subscription = $organization->platformSubscription;

        abort_if($subscription === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_none_active'));

        $subscription = $this->subscriptions->extend($subscription, $request->days());

        return successResponse(
            new PlatformSubscriptionResource($subscription->load('plan')),
            __('api.updated_success'),
        );
    }
}

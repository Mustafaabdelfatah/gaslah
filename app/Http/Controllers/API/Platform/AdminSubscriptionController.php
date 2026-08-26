<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformCycleEnum;
use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Services\Platform\PlatformSubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Platform-admin control of an organization's subscription (manage_subscriptions).
 */
class AdminSubscriptionController extends PlatformBaseController
{
    public function __construct(private readonly PlatformSubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    public function update(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate([
            'plan_id' => ['required', 'integer'],
            'status' => ['required', 'in:trial,active,past_due,cancelled'],
            'cycle' => ['required', 'in:monthly,yearly'],
            'current_period_end' => ['nullable', 'date'],
            'cancel_at_period_end' => ['nullable', 'boolean'],
            'custom_price' => ['nullable', 'numeric', 'min:0'],
        ]);

        $plan = PlatformPlan::query()->findOrFail($data['plan_id']);

        $subscription = $this->subscriptions->set(
            $organization,
            $plan,
            PlatformSubscriptionStatusEnum::from($data['status']),
            PlatformCycleEnum::from($data['cycle']),
            isset($data['current_period_end']) ? Carbon::parse($data['current_period_end']) : null,
            (bool) ($data['cancel_at_period_end'] ?? false),
            isset($data['custom_price']) ? (float) $data['custom_price'] : null,
        );

        return successResponse($subscription->load('plan'), __('api.updated_success'));
    }

    public function startTrial(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate(['plan_id' => ['nullable', 'integer']]);
        $plan = isset($data['plan_id']) ? PlatformPlan::query()->find($data['plan_id']) : null;

        return successResponse($this->subscriptions->startTrial($organization, $plan)->load('plan'), __('api.updated_success'));
    }

    public function extend(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate(['days' => ['required', 'integer', 'min:1', 'max:3650']]);

        $subscription = $organization->platformSubscription;
        abort_if($subscription === null, 422, __('api.subscription_none_active'));

        return successResponse($this->subscriptions->extend($subscription, (int) $data['days']), __('api.updated_success'));
    }
}

<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\PlatformPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Platform plan catalogue. Reading needs a live admin session; writing needs manage_plans.
 * Plans are retired (is_active=false), never hard-deleted.
 */
class AdminPlanController extends PlatformBaseController
{
    public function index(): JsonResponse
    {
        $this->requireAnyPlatformPermission([PlatformPermissionEnum::ManagePlans, PlatformPermissionEnum::ViewFinance]);

        $plans = PlatformPlan::query()
            ->withCount([
                'subscriptions',
                'subscriptions as active_count' => fn ($q) => $q->where('status', PlatformSubscriptionStatusEnum::Active->value),
            ])
            ->orderBy('sort_order')
            ->get();

        $data = $plans->map(fn (PlatformPlan $plan) => [
            ...$plan->toArray(),
            'subscribers' => $plan->subscriptions_count,
            'active' => $plan->active_count,
            'mrr' => $this->planMrr($plan),
        ]);

        return successResponse($data);
    }

    public function store(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePlans);

        $plan = PlatformPlan::query()->create($this->validated($request));

        return successResponse($plan, __('api.created_success'), 201);
    }

    public function update(Request $request, PlatformPlan $plan): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManagePlans);

        // Partial update: only supplied fields are written (an omitted optional field is
        // not silently cleared or the plan silently retired).
        $plan->update($this->validated($request, updating: true));

        return successResponse($plan->refresh(), __('api.updated_success'));
    }

    /**
     * MRR contributed by a plan's active subscriptions (yearly counted as price/12).
     */
    private function planMrr(PlatformPlan $plan): float
    {
        $mrr = $plan->subscriptions()
            ->where('status', PlatformSubscriptionStatusEnum::Active->value)
            ->get(['cycle', 'price'])
            ->sum(fn ($s) => $s->cycle->value === 'yearly' ? (float) $s->price / 12 : (float) $s->price);

        return round($mrr, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
            'monthly_price' => [$required, 'numeric', 'min:0'],
            'yearly_price' => [$required, 'numeric', 'min:0'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'max_users' => ['nullable', 'integer', 'min:1'],
            'features' => ['nullable', 'array'],
            'feature_keys' => ['nullable', 'array'],
            'is_popular' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }
}

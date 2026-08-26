<?php

namespace App\Http\Controllers\API\Tenancy\Subscriptions;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Subscriptions\SubscriptionPlanRequest;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends TenantController
{
    private const FEATURE = 'subscriptions';

    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $query = SubscriptionPlan::query()
            ->forOrganization($this->organizationId())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('price');

        return successResponse(wrapPaginate($query));
    }

    public function store(SubscriptionPlanRequest $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $plan = SubscriptionPlan::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse($plan, __('api.created_success'), 201);
    }

    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($plan);

        $plan->update($request->validated());

        return successResponse($plan->refresh(), __('api.updated_success'));
    }

    private function assertOwned(SubscriptionPlan $plan): void
    {
        abort_unless($plan->organization_id === $this->organizationId(), 404, __('api.record_not_found'));
    }
}

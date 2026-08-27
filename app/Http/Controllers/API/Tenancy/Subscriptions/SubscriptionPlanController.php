<?php

namespace App\Http\Controllers\API\Tenancy\Subscriptions;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Subscriptions\SubscriptionPlanRequest;
use App\Http\Resources\Subscriptions\SubscriptionPlanResource;
use App\Models\SubscriptionPlan;
use Illuminate\Http\JsonResponse;

class SubscriptionPlanController extends TenantController
{
    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = SubscriptionPlan::query()
            ->forOrganization($this->organizationId())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->orderBy('price');

        return successResponse(wrapPaginate($query, SubscriptionPlanResource::class));
    }

    public function store(SubscriptionPlanRequest $request): JsonResponse
    {

        $plan = SubscriptionPlan::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse(new SubscriptionPlanResource($plan), __('api.created_success'), 201);
    }

    public function update(SubscriptionPlanRequest $request, SubscriptionPlan $plan): JsonResponse
    {
        $this->assertOwned($plan);

        $plan->update($request->validated());

        return successResponse(new SubscriptionPlanResource($plan->refresh()), __('api.updated_success'));
    }
}

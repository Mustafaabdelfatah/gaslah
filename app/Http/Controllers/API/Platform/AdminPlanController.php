<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\ActiveFilter;
use App\Filters\Global\NameFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformPlanRequest;
use App\Http\Resources\Platform\PlatformPlanResource;
use App\Models\PlatformPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The platform plan catalogue.
 *
 * Reading is open to finance as well as plan managers; writing needs manage_plans (both
 * gated on the routes). Plans are retired through is_active, never hard-deleted, so this
 * controller exposes no destroy.
 */
class AdminPlanController extends BaseController
{
    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformPlan::query()->withCommercials())
            ->through([NameFilter::class, ActiveFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformPlanResource::class));
    }

    public function store(PlatformPlanRequest $request): JsonResponse
    {
        $plan = PlatformPlan::create($request->validated());

        return successResponse(new PlatformPlanResource($plan->refresh()), __('api.created_success'), 201);
    }

    public function show(PlatformPlan $plan): JsonResponse
    {
        return successResponse(new PlatformPlanResource($plan));
    }

    public function update(PlatformPlanRequest $request, PlatformPlan $plan): JsonResponse
    {
        $plan->update($request->validated());

        return successResponse(new PlatformPlanResource($plan->refresh()), __('api.updated_success'));
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Audit;

use App\Filters\Audit\AuditFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Audit\AuditEntryResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Spatie\Activitylog\Models\Activity;

/**
 * The tenant's own audit trail — who changed what, and what it looked like before.
 *
 * Read-only by design: there is no create, update or delete surface anywhere. Entries are
 * written by the models themselves as a side effect of the change they record, which is
 * what makes the trail trustworthy — nothing can act without leaving one.
 *
 * Scoped to the caller's organization and gated to the general manager on the route.
 */
class AuditController extends TenantController
{
    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send($this->scoped()->with('causer:id,name'))
            ->through([AuditFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, AuditEntryResource::class, [
            'facets' => $this->facets(),
        ]));
    }

    /**
     * The record kinds and actions this tenant has actually produced, so the UI can offer
     * filters that will return something.
     *
     * @return array{entities: array<int, string>, actions: array<int, string>}
     */
    private function facets(): array
    {
        return [
            'entities' => $this->scoped()->distinct()->orderBy('log_name')->pluck('log_name')->filter()->values()->all(),
            'actions' => $this->scoped()->distinct()->orderBy('event')->pluck('event')->filter()->values()->all(),
        ];
    }

    /**
     * @return Builder<Activity>
     */
    private function scoped(): Builder
    {
        return Activity::query()->where('organization_id', $this->organizationId());
    }
}

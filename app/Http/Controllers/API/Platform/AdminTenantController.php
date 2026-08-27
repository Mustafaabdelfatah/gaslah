<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\OrganizationScopeFilter;
use App\Filters\Platform\TenantFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\SuspendTenantRequest;
use App\Http\Requests\Platform\UpdateEntitlementsRequest;
use App\Http\Resources\Platform\PlatformAuditLogResource;
use App\Http\Resources\Platform\TenantResource;
use App\Http\Resources\Platform\TenantUserResource;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Platform\TenantDirectoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The platform operator's cross-tenant directory and per-tenant controls.
 *
 * The reserved platform-books organization is excluded from the listing (tenantsOnly) but
 * stays reachable by id, since the platform's own accounting needs it. Permissions are
 * enforced on the routes.
 */
class AdminTenantController extends BaseController
{
    public function __construct(private readonly TenantDirectoryService $directory)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(Organization::query()->tenantsOnly()->withTenantStats()->with('platformSubscription.plan'))
            ->through([TenantFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, TenantResource::class));
    }

    public function show(Organization $organization): JsonResponse
    {
        return successResponse($this->directory->detail($organization));
    }

    /**
     * The staff of a single tenant with the roles they hold across its branches.
     */
    public function users(PageRequest $request, Organization $organization): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send($this->directory->staffQuery($organization))
            ->through([OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, TenantUserResource::class));
    }

    /**
     * The platform-admin audit trail, newest first — optionally scoped to one tenant.
     */
    public function activity(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformAuditLog::query()->with(['admin:id,name', 'organization:id,name']))
            ->through([OrganizationScopeFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformAuditLogResource::class));
    }

    public function suspend(SuspendTenantRequest $request, Organization $organization): JsonResponse
    {
        $organization = $this->directory->setSuspended(
            $organization,
            $this->admin(),
            $request->isSuspending(),
            $request->reason(),
        );

        return successResponse(['is_suspended' => $organization->is_suspended], __('api.updated_success'));
    }

    public function updateEntitlements(UpdateEntitlementsRequest $request, Organization $organization): JsonResponse
    {
        $snapshot = $this->directory->applyEntitlements($organization, $this->admin(), $request->overrides());

        return successResponse($snapshot, __('api.updated_success'));
    }

    /**
     * The acting platform admin. The route middleware has already proven the session, so
     * this only narrows the type for the service call.
     */
    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}

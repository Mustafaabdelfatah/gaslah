<?php

namespace App\Http\Controllers\API\Tenancy;

use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Tenancy\StoreOrgUserRequest;
use App\Http\Requests\Tenancy\UpdateOrgUserRequest;
use App\Http\Resources\Tenancy\OrgUserResource;
use App\Services\Tenancy\OrgUserService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organization's own staff directory — who works here, where, and with what.
 *
 * A user is addressed by id rather than route-model-bound, so an account outside the
 * caller's organization answers 404 like anything else out of scope.
 */
class OrgUserController extends TenantController
{
    public function __construct(private readonly OrgUserService $users)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = $this->users->query($this->organizationId(), $request->input('search'));

        return successResponse(wrapPaginate($query, OrgUserResource::class));
    }

    /**
     * The roles this caller may assign and the full permission catalogue behind them.
     */
    public function roles(): JsonResponse
    {
        return successResponse($this->users->rolesCatalogue($this->staff(), $this->organizationId()));
    }

    public function store(StoreOrgUserRequest $request): JsonResponse
    {
        $user = $this->users->create($this->staff(), $this->organizationId(), $request->payload());

        return successResponse(
            new OrgUserResource($user->load('userBranches.branch:id,name', 'permissionOverride.items')),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }

    public function update(UpdateOrgUserRequest $request, int $user): JsonResponse
    {
        $target = $this->users->findInOrganization($this->organizationId(), $user);

        $updated = $this->users->update($this->staff(), $target, $this->organizationId(), $request->payload());

        return successResponse(
            new OrgUserResource($updated->load('userBranches.branch:id,name', 'permissionOverride.items')),
            __('api.updated_success'),
        );
    }

    /**
     * Suspend an account. Deleting one would orphan every order it took.
     */
    public function deactivate(int $user): JsonResponse
    {
        $target = $this->users->findInOrganization($this->organizationId(), $user);

        $updated = $this->users->deactivate($this->staff(), $target, $this->organizationId());

        return successResponse(
            new OrgUserResource($updated->load('userBranches.branch:id,name', 'permissionOverride.items')),
            __('api.updated_success'),
        );
    }
}

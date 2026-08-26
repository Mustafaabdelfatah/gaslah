<?php

namespace App\Http\Controllers\API\Tenancy;

use App\Http\Controllers\API\BaseController;
use App\Services\Tenancy\EntitlementService;
use App\Services\Tenancy\StaffPermissionService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * The signed-in staff member's live tenant context: who they are, the branch scope
 * in force, the permissions they hold, and what their organization is entitled to.
 *
 * Requiring an organization here is also the wall that keeps a platform token out:
 * such a token resolves no organization and is refused.
 */
class StaffContextController extends BaseController
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StaffPermissionService $permissions,
        private readonly EntitlementService $entitlements,
    ) {
        parent::__construct();
    }

    public function __invoke(): JsonResponse
    {
        $this->tenant->requireOrganizationId();

        $user = $this->tenant->user();
        $organization = $this->tenant->organization();

        return successResponse([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role?->value,
            ],
            'organization' => [
                'id' => $organization->id,
                'name' => $organization->name,
                'slug' => $organization->slug,
            ],
            'write_branch_id' => $this->tenant->writeBranchId(),
            'read_branch_ids' => $this->tenant->readBranchIds(),
            'permissions' => $this->permissions->effectiveFor($user),
            'entitlements' => $this->entitlements->snapshot($organization),
        ]);
    }
}

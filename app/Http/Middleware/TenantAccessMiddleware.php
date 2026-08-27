<?php

namespace App\Http\Middleware;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Services\Tenancy\EntitlementService;
use App\Services\Tenancy\StaffPermissionService;
use App\Services\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a staff route on the tenant rules: role floor, fine-grained permission, gated
 * feature, and subscription state.
 *
 * Being middleware matters for more than tidiness — it runs before the form request
 * validates, so someone without permission is told 403 rather than being handed a list of
 * field errors for a call they were never allowed to make.
 *
 *     ->middleware('tenant:manager')
 *     ->middleware('tenant:permission,inventory.manage')
 *     ->middleware('tenant:feature,inventory')
 *     ->middleware('tenant:active')
 *
 * Several may be combined; each is checked in the order given.
 */
class TenantAccessMiddleware
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly StaffPermissionService $permissions,
        private readonly EntitlementService $entitlements,
    ) {}

    public function handle(Request $request, Closure $next, string ...$rules): Response
    {
        // Resolving the organization also refuses a platform token, which holds no branch
        // membership.
        $organizationId = $this->tenant->requireOrganizationId();
        $user = $this->tenant->user();

        for ($index = 0; $index < count($rules); $index++) {
            match ($rules[$index]) {
                'staff' => null,
                'manager' => $this->requireRank($organizationId, StaffRoleEnum::BranchManager),
                'super_admin' => $this->requireRank($organizationId, StaffRoleEnum::SuperAdmin),
                'active' => $this->entitlements->requireActive($this->tenant->organization()),
                'permission' => $this->permissions->require($user, $rules[++$index]),
                'feature' => $this->entitlements->requireFeature($this->tenant->organization(), $rules[++$index]),
                default => abort(Response::HTTP_INTERNAL_SERVER_ERROR, "Unknown tenant access rule [{$rules[$index]}]."),
            };
        }

        return $next($request);
    }

    private function requireRank(int $organizationId, StaffRoleEnum $floor): void
    {
        $role = $this->permissions->highestRoleFor($this->tenant->user(), $organizationId);

        if ($role === null || $role->rank() < $floor->rank()) {
            abort(Response::HTTP_FORBIDDEN, __('api.unauthorized'));
        }
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Http\Controllers\API\BaseController;
use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use App\Services\Tenancy\StaffPermissionService;
use App\Services\Tenancy\TenantContext;

/**
 * Base for every staff-facing controller.
 *
 * It centralises the tenant guards so a controller can state its access rule in one
 * line: which organization the caller belongs to, the branch scopes in force, the
 * role floor, the fine-grained permission, and the subscription entitlement.
 */
abstract class TenantController extends BaseController
{
    protected TenantContext $tenant;

    protected StaffPermissionService $permissions;

    protected EntitlementService $entitlements;

    public function __construct()
    {
        parent::__construct();

        $this->tenant = app(TenantContext::class);
        $this->permissions = app(StaffPermissionService::class);
        $this->entitlements = app(EntitlementService::class);
    }

    /**
     * The authenticated staff member, guaranteed to belong to an organization.
     */
    protected function staff(): User
    {
        // Resolving the organization also refuses a platform token, which carries no
        // branch membership.
        $this->tenant->requireOrganizationId();

        return $this->tenant->user();
    }

    protected function organizationId(): int
    {
        return $this->tenant->requireOrganizationId();
    }

    protected function organization(): Organization
    {
        return $this->tenant->organization();
    }

    /**
     * Branches a listing may read, honouring the X-Branch-Id narrowing header.
     *
     * @return array<int, int>
     */
    protected function readBranchIds(): array
    {
        return $this->tenant->readBranchIds();
    }

    protected function writeBranchId(): ?int
    {
        return $this->tenant->writeBranchId();
    }

    /*
    |--------------------------------------------------------------------------
    | Access guards
    |--------------------------------------------------------------------------
    */

    /**
     * Require a fine-grained staff permission.
     */
    protected function requirePermission(StaffPermissionEnum|string $permission): void
    {
        $this->permissions->require($this->staff(), $permission);
    }

    /**
     * Require the caller to be a manager (general or branch).
     */
    protected function requireManager(): void
    {
        $role = $this->permissions->highestRoleFor($this->staff(), $this->organizationId());

        if ($role === null || $role->rank() < StaffRoleEnum::BranchManager->rank()) {
            abort(403, __('api.unauthorized'));
        }
    }

    /**
     * Require the caller to be the general manager.
     */
    protected function requireSuperAdmin(): void
    {
        if ($this->permissions->highestRoleFor($this->staff(), $this->organizationId()) !== StaffRoleEnum::SuperAdmin) {
            abort(403, __('api.unauthorized'));
        }
    }

    /**
     * Require the organization's subscription to be active (writes only).
     */
    protected function requireActiveSubscription(): void
    {
        $this->entitlements->requireActive($this->organization());
    }

    /**
     * Require a gated feature to be enabled for the organization.
     */
    protected function requireFeature(string $key): void
    {
        $this->entitlements->requireFeature($this->organization(), $key);
    }
}

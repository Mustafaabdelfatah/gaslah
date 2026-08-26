<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\User;
use App\Services\Tenancy\PlatformAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformAccessTest extends TestCase
{
    use RefreshDatabase;

    private PlatformAccessService $access;

    protected function setUp(): void
    {
        parent::setUp();

        $this->access = app(PlatformAccessService::class);
    }

    public function test_an_owner_bypasses_every_permission_check(): void
    {
        $owner = $this->platformUser(PlatformRoleEnum::Owner);

        foreach (PlatformPermissionEnum::cases() as $permission) {
            $this->assertTrue($this->access->has($owner, $permission));
        }
    }

    public function test_a_null_platform_role_is_treated_as_owner(): void
    {
        $legacy = $this->platformUser(null);

        $this->assertSame(PlatformRoleEnum::Owner, $this->access->role($legacy));
        $this->assertTrue($this->access->isOwner($legacy));
    }

    public function test_a_narrow_role_is_limited_to_its_granted_permissions(): void
    {
        $finance = $this->platformUser(PlatformRoleEnum::Finance, [
            PlatformPermissionEnum::ViewFinance,
            PlatformPermissionEnum::ManagePayouts,
        ]);

        $this->assertTrue($this->access->has($finance, PlatformPermissionEnum::ManagePayouts));
        $this->assertFalse($this->access->has($finance, PlatformPermissionEnum::ManageTenants));
    }

    public function test_a_viewer_holds_no_permissions_but_still_carries_the_flag(): void
    {
        $viewer = $this->platformUser(PlatformRoleEnum::Viewer);

        // The operator flag alone must never authorise anything, which is why the
        // cross-tenant guard demands the Owner role rather than the flag.
        $this->assertTrue($this->access->isPlatformAdmin($viewer));
        $this->assertSame([], $this->access->permissions($viewer));
        $this->assertFalse($this->access->has($viewer, PlatformPermissionEnum::ManageTenants));
    }

    public function test_a_non_admin_is_never_a_platform_admin(): void
    {
        $staff = $this->createUser();

        $this->assertFalse($this->access->isPlatformAdmin($staff));
        $this->assertNull($this->access->role($staff));
    }

    public function test_a_disabled_admin_loses_platform_access(): void
    {
        $owner = $this->platformUser(PlatformRoleEnum::Owner);
        $owner->update(['is_active' => false]);

        $this->assertFalse($this->access->isPlatformAdmin($owner->fresh()));
    }

    public function test_the_cross_tenant_guard_is_owner_only(): void
    {
        $support = $this->platformUser(PlatformRoleEnum::Support, [
            PlatformPermissionEnum::ManageTenants,
        ]);

        // Holding manage_tenants is not enough for impersonation-grade authority.
        $this->assertAborts(403, fn () => $this->access->requireOwner($support));
        $this->access->requireOwner($this->platformUser(PlatformRoleEnum::Owner));
    }

    public function test_requiring_a_missing_permission_is_forbidden(): void
    {
        $sales = $this->platformUser(PlatformRoleEnum::Sales, [PlatformPermissionEnum::ManageLeads]);

        $this->assertAborts(
            403,
            fn () => $this->access->requirePermission($sales, PlatformPermissionEnum::ManagePayouts)
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, PlatformPermissionEnum>  $permissions
     */
    private function platformUser(?PlatformRoleEnum $role, array $permissions = []): User
    {
        $user = $this->createUser();
        $user->forceFill([
            'is_platform_owner' => true,
            'platform_role' => $role?->value,
        ])->save();

        foreach ($permissions as $permission) {
            $user->platformPermissions()->create(['permission' => $permission->value]);
        }

        return $user;
    }
}

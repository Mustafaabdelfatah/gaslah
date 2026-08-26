<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Services\Tenancy\StaffPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StaffPermissionTest extends TestCase
{
    use RefreshDatabase;

    private StaffPermissionService $permissions;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissions = app(StaffPermissionService::class);
    }

    public function test_role_defaults_apply_when_no_override_exists(): void
    {
        [, $branch] = $this->createTenant();
        $cashier = $this->createStaff($branch, StaffRoleEnum::Cashier);

        $this->assertTrue($this->permissions->has($cashier, StaffPermissionEnum::PosCheckout));
        $this->assertTrue($this->permissions->has($cashier, StaffPermissionEnum::ShiftsManage));

        // Accounting and settings belong to the general manager alone.
        $this->assertFalse($this->permissions->has($cashier, StaffPermissionEnum::AccountingView));
        $this->assertFalse($this->permissions->has($cashier, StaffPermissionEnum::SettingsManage));
    }

    public function test_reception_cannot_take_payment(): void
    {
        [, $branch] = $this->createTenant();
        $reception = $this->createStaff($branch, StaffRoleEnum::Reception);

        $this->assertTrue($this->permissions->has($reception, StaffPermissionEnum::OrdersManage));
        $this->assertFalse($this->permissions->has($reception, StaffPermissionEnum::PosCheckout));
    }

    public function test_override_replaces_the_role_defaults_instead_of_extending_them(): void
    {
        [, $branch] = $this->createTenant();
        $manager = $this->createStaff($branch, StaffRoleEnum::BranchManager);

        $this->giveOverride($manager, [StaffPermissionEnum::ReportsView]);

        $effective = $this->permissions->effectiveFor($manager);

        $this->assertSame([StaffPermissionEnum::ReportsView->value], $effective);

        // Permissions the role would otherwise grant are gone, not merged back in.
        $this->assertFalse($this->permissions->has($manager, StaffPermissionEnum::PosCheckout));
        $this->assertFalse($this->permissions->has($manager, StaffPermissionEnum::UsersManage));
    }

    public function test_an_override_with_no_entries_grants_nothing(): void
    {
        [, $branch] = $this->createTenant();
        $manager = $this->createStaff($branch, StaffRoleEnum::SuperAdmin);

        // An empty override is a deliberate state: strip the account back without
        // touching its role.
        $this->giveOverride($manager, []);

        $this->assertSame([], $this->permissions->effectiveFor($manager));
        $this->assertFalse($this->permissions->has($manager, StaffPermissionEnum::PosCheckout));
    }

    public function test_clearing_the_override_restores_the_role_defaults(): void
    {
        [, $branch] = $this->createTenant();
        $cashier = $this->createStaff($branch, StaffRoleEnum::Cashier);

        $override = $this->giveOverride($cashier, []);
        $this->assertSame([], $this->permissions->effectiveFor($cashier));

        $override->delete();

        $this->assertTrue($this->permissions->has($cashier, StaffPermissionEnum::PosCheckout));
    }

    public function test_the_highest_role_across_branches_decides_the_permissions(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);

        $user = $this->createStaff($main, StaffRoleEnum::Reception);
        $this->attachToBranch($user, $second, StaffRoleEnum::SuperAdmin);

        $this->assertSame(StaffRoleEnum::SuperAdmin, $this->permissions->highestRoleFor($user));
        $this->assertTrue($this->permissions->has($user, StaffPermissionEnum::AccountingView));
    }

    public function test_a_demotion_takes_effect_without_re_authenticating(): void
    {
        [, $branch] = $this->createTenant();
        $user = $this->createStaff($branch, StaffRoleEnum::SuperAdmin);

        $this->assertTrue($this->permissions->has($user, StaffPermissionEnum::SettingsManage));

        $user->userBranches()->where('branch_id', $branch->getKey())
            ->update(['role' => StaffRoleEnum::Cashier->value]);

        // Permissions are recomputed from the membership rows, so the stale mirror
        // column on the user cannot keep the old authority alive.
        $this->assertFalse($this->permissions->has($user->fresh(), StaffPermissionEnum::SettingsManage));
    }

    public function test_a_manager_cannot_grant_a_role_above_their_own(): void
    {
        [, $branch] = $this->createTenant();
        $manager = $this->createStaff($branch, StaffRoleEnum::BranchManager);

        $this->assertTrue($this->permissions->canAssignRole($manager, StaffRoleEnum::Cashier));
        $this->assertTrue($this->permissions->canAssignRole($manager, StaffRoleEnum::BranchManager));
        $this->assertFalse($this->permissions->canAssignRole($manager, StaffRoleEnum::SuperAdmin));
    }

    public function test_requiring_a_missing_permission_refuses_the_request(): void
    {
        [, $branch] = $this->createTenant();
        $cashier = $this->createStaff($branch, StaffRoleEnum::Cashier);

        $this->assertAborts(
            403,
            fn () => $this->permissions->require($cashier, StaffPermissionEnum::AccountingView)
        );
    }

    public function test_the_mirror_column_follows_the_membership_roles(): void
    {
        [$organization, $main] = $this->createTenant();
        $second = Branch::factory()->create(['organization_id' => $organization->getKey()]);

        $user = $this->createStaff($main, StaffRoleEnum::Cashier);
        $this->assertSame(StaffRoleEnum::Cashier, $user->fresh()->role);

        $this->attachToBranch($user, $second, StaffRoleEnum::SuperAdmin);
        $this->assertSame(StaffRoleEnum::SuperAdmin, $user->fresh()->role);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, StaffPermissionEnum>  $permissions
     */
    private function giveOverride(User $user, array $permissions): UserPermissionOverride
    {
        $override = UserPermissionOverride::query()->create(['user_id' => $user->getKey()]);

        foreach ($permissions as $permission) {
            $override->items()->create(['permission' => $permission->value]);
        }

        return $override;
    }
}

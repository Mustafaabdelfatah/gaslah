<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use App\Services\Tenancy\StaffPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrgUserApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
    }

    public function test_an_admin_hires_a_staff_member_into_a_branch(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/api/org/users', [
            'name' => 'سعد الكاشير',
            'email' => 'saad@example.com',
            'password' => 'secret123',
            'branches' => [['branch_id' => $this->branch->getKey(), 'role' => 'cashier']],
        ])->assertCreated();

        $response->assertJsonPath('data.branches.0.role', 'cashier')
            ->assertJsonPath('data.branches.0.branch_id', $this->branch->getKey())
            ->assertJsonPath('data.permissions', null)
            ->assertJsonPath('data.is_active', true);

        $this->getJson('/api/org/users')->assertOk()->assertJsonPath('data.total', 2);
    }

    public function test_the_listing_is_scoped_to_the_organization(): void
    {
        $this->actingAsAdmin();

        [, $otherBranch] = $this->createTenant();
        $foreign = $this->createStaff($otherBranch, StaffRoleEnum::Cashier);

        $response = $this->getJson('/api/org/users')->assertOk();

        $ids = array_column($response->json('data.data'), 'id');
        $this->assertNotContains($foreign->getKey(), $ids);

        // And it cannot be reached by id either.
        $this->putJson("/api/org/users/{$foreign->getKey()}", ['name' => 'مُخترق'])->assertStatus(404);
    }

    public function test_nobody_may_grant_a_role_above_their_own(): void
    {
        $manager = $this->createStaff($this->branch, StaffRoleEnum::BranchManager);
        $this->actingAsStaff($manager);

        $this->postJson('/api/org/users', [
            'name' => 'ترقية',
            'email' => 'promote@example.com',
            'password' => 'secret123',
            'branches' => [['branch_id' => $this->branch->getKey(), 'role' => 'super_admin']],
        ])->assertStatus(403);
    }

    public function test_an_explicit_override_replaces_the_role_defaults_and_can_be_cleared(): void
    {
        $this->actingAsAdmin();
        $staff = $this->createStaff($this->branch, StaffRoleEnum::Cashier);

        $this->putJson("/api/org/users/{$staff->getKey()}", [
            'permissions' => ['orders.manage'],
        ])->assertOk()->assertJsonPath('data.permissions', ['orders.manage']);

        $this->assertSame(['orders.manage'], app(StaffPermissionService::class)->effectiveFor($staff->fresh()));

        // An override granting nothing is still an override.
        $this->putJson("/api/org/users/{$staff->getKey()}", ['permissions' => []])
            ->assertOk()->assertJsonPath('data.permissions', []);
        $this->assertSame([], app(StaffPermissionService::class)->effectiveFor($staff->fresh()));

        // Null clears it, so the role defaults apply again.
        $this->putJson("/api/org/users/{$staff->getKey()}", ['permissions' => null])
            ->assertOk()->assertJsonPath('data.permissions', null);
        $this->assertSame(
            StaffRoleEnum::Cashier->permissionValues(),
            app(StaffPermissionService::class)->effectiveFor($staff->fresh()),
        );
    }

    public function test_moving_a_member_between_branches_replaces_the_old_membership(): void
    {
        $this->actingAsAdmin();
        $staff = $this->createStaff($this->branch, StaffRoleEnum::Reception);
        $second = Branch::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->putJson("/api/org/users/{$staff->getKey()}", [
            'branches' => [['branch_id' => $second->getKey(), 'role' => 'branch_manager']],
        ])->assertOk()->assertJsonCount(1, 'data.branches');

        $this->assertSame(StaffRoleEnum::BranchManager, $staff->fresh()->role);
    }

    public function test_deactivating_yourself_is_refused(): void
    {
        $admin = $this->actingAsAdmin();

        $this->postJson("/api/org/users/{$admin->getKey()}/deactivate")->assertStatus(422);
    }

    public function test_deactivation_suspends_the_account_without_deleting_it(): void
    {
        $this->actingAsAdmin();
        $staff = $this->createStaff($this->branch, StaffRoleEnum::Cashier);

        $this->postJson("/api/org/users/{$staff->getKey()}/deactivate")
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertNotNull(User::query()->find($staff->getKey()));
    }

    public function test_a_cashier_cannot_reach_the_staff_directory(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/org/users')->assertStatus(403);
    }

    public function test_the_roles_catalogue_never_offers_a_role_above_the_caller(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        $response = $this->getJson('/api/org/users/roles')->assertOk();

        $this->assertNotContains('super_admin', array_column($response->json('data.roles'), 'value'));
        $this->assertNotEmpty($response->json('data.permission_catalog'));
    }

    private function actingAsAdmin(): User
    {
        return $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ImpersonationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
    }

    public function test_an_owner_enters_a_tenant_as_its_general_manager(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        $manager = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);

        Sanctum::actingAs($this->owner());

        $response = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertOk()
            // The most privileged member is preferred, so support sees what the owner sees.
            ->assertJsonPath('data.acting_as.id', $manager->getKey())
            ->assertJsonStructure(['data' => ['token', 'expires_at', 'organization', 'acting_as']]);

        // The borrowed token really works, and lands scoped to that tenant.
        $this->asNobody();
        $this->withHeader('Authorization', 'Bearer '.$response->json('data.token'))
            ->getJson('/api/org/entitlements')
            ->assertOk();
    }

    public function test_the_session_expires_on_its_own(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        Sanctum::actingAs($this->owner());

        $token = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertOk()->json('data.token');

        $this->travel(31)->minutes();

        $this->asNobody();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/org/entitlements')
            ->assertStatus(401);
    }

    public function test_stopping_kills_the_borrowed_session_without_touching_the_console_one(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $token = $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertOk()->json('data.token');

        $this->postJson('/api/admin/impersonate/stop')->assertOk()->assertJsonPath('data.ended', 1);

        // The borrowed session is dead immediately — it must not outlive the support call.
        $this->asNobody();
        $this->withHeader('Authorization', 'Bearer '.$token)
            ->getJson('/api/org/entitlements')
            ->assertStatus(401);

        // The operator is still signed in to the console.
        Sanctum::actingAs($owner);
        $this->getJson('/api/admin/tenants')->assertOk();
    }

    public function test_a_platform_admin_inside_the_tenant_is_never_the_target(): void
    {
        // A platform admin who also holds a branch role. Entering as them would let the
        // guard re-stamp the session as a platform one on the next request.
        $insider = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        $insider->forceFill(['is_platform_owner' => true])->save();

        Sanctum::actingAs($this->owner());
        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertStatus(422);

        // With an ordinary member present, that one is chosen instead.
        $ordinary = $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.acting_as.id', $ordinary->getKey());
    }

    public function test_an_inactive_member_is_never_the_target(): void
    {
        $disabled = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        $disabled->forceFill(['is_active' => false])->save();
        $active = $this->createStaff($this->branch, StaffRoleEnum::Reception);

        Sanctum::actingAs($this->owner());
        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")
            ->assertOk()
            ->assertJsonPath('data.acting_as.id', $active->getKey());
    }

    public function test_it_is_audited(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")->assertOk();

        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'impersonate',
        ]);
    }

    public function test_no_permission_grants_the_right_to_act_as_somebody_else(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);

        // Holding every tenant-management permission is still not enough: this is the
        // owner's alone.
        $support = $this->createUser();
        $support->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Support->value])->save();
        $support->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageTenants->value]);
        Sanctum::actingAs($support);

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/impersonate")->assertStatus(403);
    }

    /**
     * Drop the acting user Sanctum::actingAs pinned, so the next request is authenticated
     * by its Authorization header instead — which is the whole point when the token under
     * test is a real one.
     */
    private function asNobody(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

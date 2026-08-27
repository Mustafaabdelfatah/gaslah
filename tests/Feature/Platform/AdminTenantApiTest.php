<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantApiTest extends TestCase
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

    public function test_the_directory_lists_tenants_with_counts(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/tenants')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $this->organization->getKey())
            ->assertJsonPath('data.data.0.branches_count', 1)
            ->assertJsonPath('data.data.0.status', 'grandfathered');
    }

    public function test_a_non_admin_cannot_read_the_directory(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/tenants')->assertStatus(403);
    }

    public function test_the_detail_view_returns_entitlements_and_stats(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.tenant.id', $this->organization->getKey())
            ->assertJsonStructure(['data' => ['tenant' => ['branches_count', 'feature_overrides'], 'entitlements' => ['features', 'limits'], 'at_risk', 'recent_events']]);
    }

    public function test_suspending_a_tenant_makes_it_read_only_and_is_audited(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/suspend", ['suspended' => true, 'reason' => 'non-payment'])
            ->assertOk()
            ->assertJsonPath('data.is_suspended', true);

        $this->assertFalse(app(EntitlementService::class)->isActive($this->organization->fresh()));
        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $this->organization->getKey(),
            'action' => 'suspend',
        ]);

        // Reactivate flips it back and logs the reverse action.
        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/suspend", ['suspended' => false])
            ->assertOk();

        $this->assertTrue(app(EntitlementService::class)->isActive($this->organization->fresh()));
        $this->assertSame(2, PlatformAuditLog::query()->where('organization_id', $this->organization->getKey())->count());
    }

    public function test_entitlement_overrides_toggle_gated_features_and_limits(): void
    {
        Sanctum::actingAs($this->owner());

        $this->patchJson("/api/admin/tenants/{$this->organization->getKey()}/entitlements", [
            'feature_overrides' => ['delivery' => false],
            'max_branches_override' => 2,
        ])->assertOk();

        $organization = $this->organization->fresh();
        $entitlements = app(EntitlementService::class);

        $this->assertFalse($entitlements->hasFeature($organization, 'delivery'));
        $this->assertSame(2, $entitlements->maxBranches($organization));
    }

    public function test_an_override_naming_an_unknown_or_core_feature_is_rejected(): void
    {
        Sanctum::actingAs($this->owner());

        $this->patchJson("/api/admin/tenants/{$this->organization->getKey()}/entitlements", [
            'feature_overrides' => ['not_a_feature' => true],
        ])->assertStatus(422)->assertJsonValidationErrors('feature_overrides');

        $this->assertNull($this->organization->fresh()->feature_overrides);
    }

    public function test_an_entitlement_update_only_writes_the_fields_it_was_sent(): void
    {
        Sanctum::actingAs($this->owner());
        $url = "/api/admin/tenants/{$this->organization->getKey()}/entitlements";

        $this->patchJson($url, ['feature_overrides' => ['delivery' => false]])->assertOk();
        $this->patchJson($url, ['max_users_override' => 5])->assertOk();

        // Raising the seat ceiling must not wipe the feature override set earlier.
        $organization = $this->organization->fresh();
        $this->assertSame(['delivery' => false], $organization->feature_overrides);
        $this->assertSame(5, app(EntitlementService::class)->maxUsers($organization));
    }

    public function test_the_directory_can_be_searched_and_filtered(): void
    {
        [$other] = $this->createTenant();
        $other->forceFill(['name' => 'مغسلة الفردوس', 'is_suspended' => true])->save();

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/tenants?search=الفردوس')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $other->getKey());

        $this->getJson('/api/admin/tenants?is_suspended=1')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.is_suspended', true);
    }

    public function test_the_tenant_staff_listing_is_paginated_with_roles(): void
    {
        $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/users")
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['id', 'name', 'email', 'roles']], 'total']])
            ->assertJsonPath('data.total', 1);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

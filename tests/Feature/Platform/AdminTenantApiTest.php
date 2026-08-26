<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
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
            ->assertJsonPath('data.organization.id', $this->organization->getKey())
            ->assertJsonStructure(['data' => ['entitlements' => ['features', 'limits'], 'stats' => ['orders_count', 'at_risk'], 'recent_events']]);
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
            'feature_overrides' => ['delivery' => false, 'not_a_feature' => true],
            'max_branches_override' => 2,
        ])->assertOk();

        $organization = $this->organization->fresh();
        $entitlements = app(EntitlementService::class);

        // The unknown key is dropped; the real gated key is disabled.
        $this->assertFalse($entitlements->hasFeature($organization, 'delivery'));
        $this->assertArrayNotHasKey('not_a_feature', $organization->feature_overrides);
        $this->assertSame(2, $entitlements->maxBranches($organization));
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

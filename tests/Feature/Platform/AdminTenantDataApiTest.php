<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminTenantDataApiTest extends TestCase
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

    /*
    |--------------------------------------------------------------------------
    | Archiving
    |--------------------------------------------------------------------------
    */

    public function test_archiving_suspends_the_account_too_and_leaves_a_trail(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/archive", ['reason' => 'ended contract'])
            ->assertOk();

        $organization = $this->organization->refresh();

        // An archived account that could still be written to would be archived in name
        // only, so both are set together.
        $this->assertNotNull($organization->archived_at);
        $this->assertTrue((bool) $organization->is_suspended);

        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'archive',
        ]);

        $this->assertSame('ended contract', PlatformAuditLog::query()->latest('id')->first()->meta['reason']);
    }

    public function test_unarchiving_lifts_the_suspension_with_it(): void
    {
        Sanctum::actingAs($this->owner());
        $key = $this->organization->getKey();

        $this->postJson("/api/admin/tenants/{$key}/archive")->assertOk();
        $this->postJson("/api/admin/tenants/{$key}/unarchive")->assertOk();

        $organization = $this->organization->refresh();
        $this->assertNull($organization->archived_at);
        $this->assertFalse((bool) $organization->is_suspended);
    }

    public function test_archiving_twice_is_refused(): void
    {
        Sanctum::actingAs($this->owner());
        $key = $this->organization->getKey();

        $this->postJson("/api/admin/tenants/{$key}/archive")->assertOk();
        $this->postJson("/api/admin/tenants/{$key}/archive")->assertStatus(409);
    }

    public function test_unarchiving_an_account_that_was_never_archived_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/unarchive")->assertStatus(409);
    }

    public function test_there_is_no_route_to_delete_a_tenant(): void
    {
        Sanctum::actingAs($this->owner());

        // Deliberate: every tenant shares one database, and a cascade from an organization
        // row would take orders, invoices and accounting entries with it.
        $this->deleteJson("/api/admin/tenants/{$this->organization->getKey()}")->assertStatus(405);
    }

    /*
    |--------------------------------------------------------------------------
    | Export
    |--------------------------------------------------------------------------
    */

    public function test_the_owner_exports_a_tenants_bundle(): void
    {
        Customer::factory()->count(3)->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/export")
            ->assertOk()
            ->assertJsonPath('data.organization.id', $this->organization->getKey())
            ->assertJsonCount(1, 'data.branches')
            ->assertJsonCount(3, 'data.customers')
            ->assertJsonPath('data.customers_total', 3)
            ->assertJsonPath('data.customers_truncated', false)
            ->assertJsonPath('data.export_note', null);
    }

    public function test_the_export_carries_only_the_named_tenants_customers(): void
    {
        [$other, $otherBranch] = $this->createTenant();
        Customer::factory()->count(2)->create([
            'organization_id' => $other->getKey(), 'branch_id' => $otherBranch->getKey(),
        ]);
        Customer::factory()->create([
            'organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/export")
            ->assertOk()
            ->assertJsonPath('data.customers_total', 1);
    }

    public function test_the_export_is_recorded_against_the_admin_who_took_it(): void
    {
        $owner = $this->owner();
        Sanctum::actingAs($owner);

        $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/export")->assertOk();

        // Personal data left the building; who took it and how much is not optional.
        $this->assertDatabaseHas('platform_audit_logs', [
            'admin_id' => $owner->getKey(),
            'organization_id' => $this->organization->getKey(),
            'action' => 'export',
        ]);
    }

    public function test_a_tenant_manager_may_archive_but_never_export(): void
    {
        Sanctum::actingAs($this->tenantManager());
        $key = $this->organization->getKey();

        $this->postJson("/api/admin/tenants/{$key}/archive")->assertOk();

        // Archiving is operations; handing over a book of customers by name and phone is
        // the owner's call alone, however senior the operator.
        $this->getJson("/api/admin/tenants/{$key}/export")->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Commercial profile
    |--------------------------------------------------------------------------
    */

    public function test_the_operator_corrects_a_tenants_commercial_details(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/profile", [
            'name' => 'مغسلة الرياض',
            'tax_rate' => 15,
            'vat_number' => '310000000000003',
            'phone' => '  ',
        ])
            ->assertOk()
            ->assertJsonPath('data.name', 'مغسلة الرياض');

        $organization = $this->organization->refresh();
        $this->assertSame('310000000000003', $organization->vat_number);
        $this->assertNull($organization->phone);

        // Someone else's business was edited, so the trail names who did it.
        $this->assertDatabaseHas('platform_audit_logs', [
            'organization_id' => $organization->getKey(),
            'action' => 'update_profile',
        ]);
    }

    public function test_a_tax_rate_outside_a_percentage_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/profile", [
            'name' => 'X', 'tax_rate' => 150,
        ])->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    /**
     * A platform admin who holds manage_tenants but is not the owner.
     */
    private function tenantManager(): User
    {
        $user = $this->createUser();
        $user->forceFill([
            'is_platform_owner' => true,
            'platform_role' => PlatformRoleEnum::Support->value,
        ])->save();

        // A narrow role reads its grants from the table, not from the role's default map.
        $user->platformPermissions()->create(['permission' => PlatformPermissionEnum::ManageTenants->value]);

        return $user;
    }
}

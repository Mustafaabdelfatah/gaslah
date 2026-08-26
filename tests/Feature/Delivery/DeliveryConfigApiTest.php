<?php

namespace Tests\Feature\Delivery;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Driver;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeliveryConfigApiTest extends TestCase
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
    | Settings
    |--------------------------------------------------------------------------
    */
    public function test_settings_return_defaults_with_the_available_block(): void
    {
        $this->actingAsManager();

        $this->getJson('/api/delivery/settings')
            ->assertOk()
            ->assertJsonPath('data.methods.selfDelivery', true)
            ->assertJsonPath('data.available.integration', false);
    }

    public function test_the_general_manager_saves_settings_and_unavailable_methods_are_forced_off(): void
    {
        $this->actingAsManager();

        $this->putJson('/api/delivery/settings', [
            'methods' => ['selfDelivery' => true, 'integration' => true],
            'self' => ['feeMode' => 'per_direction', 'pickupFee' => 8, 'deliveryFee' => 12],
        ])
            ->assertOk()
            // integration is not available, so it settles off.
            ->assertJsonPath('data.methods.integration', false)
            ->assertJsonPath('data.methods.selfDelivery', true)
            ->assertJsonPath('data.self.pickupFee', 8);
    }

    public function test_a_branch_manager_cannot_change_settings(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        $this->putJson('/api/delivery/settings', ['methods' => ['selfDelivery' => false]])
            ->assertStatus(403);
    }

    public function test_delivery_is_refused_when_the_feature_is_disabled(): void
    {
        $this->organization->update(['feature_overrides' => ['delivery' => false]]);
        $this->actingAsManager();

        $this->getJson('/api/delivery/settings')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Zones
    |--------------------------------------------------------------------------
    */
    public function test_a_manager_creates_zones_that_get_a_running_sort_order(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/delivery/zones', ['name' => 'North', 'fee' => 20])
            ->assertCreated()->assertJsonPath('data.sort_order', 0);
        $this->postJson('/api/delivery/zones', ['name' => 'South', 'fee' => 25])
            ->assertCreated()->assertJsonPath('data.sort_order', 1);

        $this->getJson('/api/delivery/zones')->assertOk()->assertJsonPath('data.0.name', 'North');
    }

    public function test_a_cashier_cannot_create_a_zone(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->postJson('/api/delivery/zones', ['name' => 'X', 'fee' => 5])->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    public function test_a_manager_creates_a_driver_and_the_phone_is_unique_system_wide(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/delivery/drivers', ['name' => 'Ali', 'phone' => '0590000001'])
            ->assertCreated();

        // Same phone, even in another organization, is refused.
        $other = $this->createOrganization();
        Driver::factory()->create(['organization_id' => $other->getKey(), 'branch_id' => Branch::factory()->create(['organization_id' => $other->getKey()])->getKey(), 'phone' => '0590000002']);

        $this->postJson('/api/delivery/drivers', ['name' => 'Dup', 'phone' => '0590000002'])
            ->assertStatus(422);
    }

    public function test_the_driver_listing_includes_platform_drivers_only_when_enabled(): void
    {
        $this->actingAsManager();
        Driver::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'name' => 'Own']);
        Driver::factory()->platform()->create(['branch_id' => $this->branch->getKey(), 'name' => 'PlatformGuy']);

        // Default settings: platformDriver off — only own driver is eligible.
        $names = collect($this->getJson('/api/delivery/drivers')->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Own'));
        $this->assertFalse($names->contains('PlatformGuy'));
    }

    public function test_staff_cannot_edit_a_platform_driver(): void
    {
        $this->actingAsManager();
        $platform = Driver::factory()->platform()->create(['branch_id' => $this->branch->getKey()]);

        $this->putJson("/api/delivery/drivers/{$platform->getKey()}", ['name' => 'Hacked'])
            ->assertStatus(404);
    }

    private function actingAsManager(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }
}

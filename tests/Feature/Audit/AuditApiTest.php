<?php

namespace Tests\Feature\Audit;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class AuditApiTest extends TestCase
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

    public function test_a_change_is_recorded_with_what_it_looked_like_before(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $id = $this->postJson('/api/customers', ['name' => 'سارة', 'phone' => '0501110000'])
            ->assertCreated()->json('data.id');

        $this->putJson("/api/customers/{$id}", ['name' => 'سارة علي', 'phone' => '0501110000'])->assertOk();

        $entries = $this->getJson('/api/audit?entity=Customer')->assertOk()->json('data.data.data');

        // Newest first: the rename, carrying both sides of the change.
        $this->assertSame('updated', $entries[0]['action']);
        $this->assertSame('سارة', $entries[0]['before']['name']);
        $this->assertSame('سارة علي', $entries[0]['after']['name']);
        $this->assertSame('created', $entries[1]['action']);
    }

    public function test_the_trail_is_scoped_to_the_callers_organization(): void
    {
        // Another tenant's activity, written the same way.
        [$otherOrg, $otherBranch] = $this->createTenant();
        $this->actingAsStaff($this->createStaff($otherBranch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/customers', ['name' => 'عميل الغير', 'phone' => '0509990000'])->assertCreated();

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/customers', ['name' => 'عميلي', 'phone' => '0501110000'])->assertCreated();

        $names = collect($this->getJson('/api/audit')->assertOk()->json('data.data.data'))
            ->pluck('after.name')
            ->filter();

        $this->assertTrue($names->contains('عميلي'));
        $this->assertFalse($names->contains('عميل الغير'), 'another tenant’s activity must never appear');
    }

    public function test_it_names_who_made_the_change(): void
    {
        $manager = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);
        $this->actingAsStaff($manager);

        $this->postJson('/api/customers', ['name' => 'سارة', 'phone' => '0501110000'])->assertCreated();

        $this->getJson('/api/audit')
            ->assertOk()
            ->assertJsonPath('data.data.data.0.causer_id', $manager->getKey())
            ->assertJsonPath('data.data.data.0.causer', $manager->name);
    }

    public function test_it_offers_only_filters_that_would_return_something(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/customers', ['name' => 'سارة', 'phone' => '0501110000'])->assertCreated();

        $facets = $this->getJson('/api/audit')->assertOk()->json('data.facets');

        $this->assertContains('Customer', $facets['entities']);
        $this->assertContains('created', $facets['actions']);
    }

    public function test_a_malformed_date_filter_degrades_instead_of_failing(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/customers', ['name' => 'سارة', 'phone' => '0501110000'])->assertCreated();

        // An audit screen should fall back to unfiltered, never to a 500. Creating the
        // tenant logs its branch too, so the trail holds more than just the customer.
        $unfiltered = $this->getJson('/api/audit')->assertOk()->json('data.data.total');

        $this->getJson('/api/audit?from=not-a-date&to=%%%')
            ->assertOk()
            ->assertJsonPath('data.data.total', $unfiltered);
    }

    public function test_only_the_general_manager_may_read_it(): void
    {
        // It shows what every member did, including each other — a branch manager is not
        // entitled to that.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));
        $this->getJson('/api/audit')->assertStatus(403);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $this->getJson('/api/audit')->assertStatus(403);
    }

    public function test_the_trail_cannot_be_written_or_erased_through_the_api(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/customers', ['name' => 'سارة', 'phone' => '0501110000'])->assertCreated();

        $entry = Activity::query()->where('organization_id', $this->organization->getKey())->firstOrFail();

        // No create, update or delete surface exists — an audit trail an actor can edit is
        // not a trail. /audit answers reads only, and there is no per-entry route at all.
        $this->postJson('/api/audit', ['description' => 'forged'])->assertStatus(405);
        $this->putJson("/api/audit/{$entry->getKey()}", ['description' => 'x'])->assertNotFound();
        $this->deleteJson("/api/audit/{$entry->getKey()}")->assertNotFound();
    }
}

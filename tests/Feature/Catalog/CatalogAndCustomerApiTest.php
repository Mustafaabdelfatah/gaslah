<?php

namespace Tests\Feature\Catalog;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Accounting\ChartOfAccountsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CatalogAndCustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    public function test_a_manager_creates_a_product_with_price_cells(): void
    {
        $this->actingAsManager();
        $category = $this->category();

        $response = $this->postJson('/api/catalog/products', [
            'category_id' => $category->getKey(),
            'name' => 'Thobe',
            'cells' => [
                'wash_iron' => ['base_price' => 12, 'express_surcharge' => 6, 'is_express_available' => true],
                'iron' => ['base_price' => 7],
            ],
        ]);

        $response->assertCreated();
        // One price cell per requested service type.
        $this->assertSame(2, Service::query()->where('product_id', $response->json('data.id'))->count());
    }

    public function test_renaming_a_product_syncs_the_name_onto_its_cells(): void
    {
        $this->actingAsManager();
        $category = $this->category();

        $productId = $this->postJson('/api/catalog/products', [
            'category_id' => $category->getKey(),
            'name' => 'Thobe',
            'cells' => ['wash' => ['base_price' => 5]],
        ])->json('data.id');

        $this->patchJson("/api/catalog/products/{$productId}", ['name' => 'White Thobe'])->assertOk();

        $this->assertSame('White Thobe', Service::query()->where('product_id', $productId)->value('name'));
    }

    public function test_the_catalog_view_returns_priced_cells(): void
    {
        $this->actingAsManager();
        $category = $this->category();
        $this->postJson('/api/catalog/products', [
            'category_id' => $category->getKey(),
            'name' => 'Thobe',
            'cells' => ['wash_iron' => ['base_price' => 12, 'express_surcharge' => 6, 'is_express_available' => true]],
        ]);

        $response = $this->getJson('/api/catalog');

        $response->assertOk();
        $cell = $response->json('data.categories.0.products.0.cells.wash_iron');
        // Express price is recomputed server-side: 12 + 6.
        $this->assertEquals(12.0, $cell['normal']);
        $this->assertEquals(18.0, $cell['express']);
    }

    public function test_the_catalog_view_lists_every_active_category_even_empty_ones(): void
    {
        $this->actingAsManager();
        $empty = $this->category();
        $stocked = $this->category();
        $this->postJson('/api/catalog/products', [
            'category_id' => $stocked->getKey(),
            'name' => 'Thobe',
            'cells' => ['wash' => ['base_price' => 5]],
        ]);

        $response = $this->getJson('/api/catalog')->assertOk();

        // The sellable tree drops the empty category; the full list keeps it.
        $this->assertCount(1, $response->json('data.categories'));
        $this->assertEqualsCanonicalizing(
            [$empty->getKey(), $stocked->getKey()],
            array_column($response->json('data.all_categories'), 'id'),
        );
    }

    public function test_a_manager_reorders_products_within_a_category(): void
    {
        $this->actingAsManager();
        $category = $this->category();
        $ids = collect(['Thobe', 'Shirt'])->map(fn (string $name) => $this->postJson('/api/catalog/products', [
            'category_id' => $category->getKey(),
            'name' => $name,
            'cells' => ['wash' => ['base_price' => 5]],
        ])->json('data.id'))->all();

        $this->putJson('/api/catalog/products/reorder', ['ids' => array_reverse($ids)])->assertOk();

        $ordered = $this->getJson('/api/catalog')->json('data.categories.0.products');
        $this->assertSame('Shirt', $ordered[0]['name']);
        $this->assertSame('Thobe', $ordered[1]['name']);
    }

    public function test_reordering_a_foreign_product_fails_the_whole_request(): void
    {
        $this->actingAsManager();
        $category = $this->category();
        $ownId = $this->postJson('/api/catalog/products', [
            'category_id' => $category->getKey(),
            'name' => 'Thobe',
            'cells' => ['wash' => ['base_price' => 5]],
        ])->json('data.id');

        $this->putJson('/api/catalog/products/reorder', ['ids' => [$ownId, 999999]])->assertStatus(404);
    }

    public function test_a_customer_is_created_and_the_phone_is_unique_per_organization(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/customers', ['name' => 'Sara', 'phone' => '0501112222'])->assertCreated();

        // The same phone in the same organization is refused — as a field error, so the
        // client can show it beside the input.
        $this->postJson('/api/customers', ['name' => 'Other', 'phone' => '0501112222'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');
    }

    public function test_a_customer_keeps_their_own_phone_when_edited(): void
    {
        $this->actingAsManager();
        $customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'phone' => '0509998888',
        ]);

        // The uniqueness rule must ignore the row being edited.
        $this->putJson("/api/customers/{$customer->getKey()}", ['name' => 'Renamed', 'phone' => '0509998888'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Renamed');
    }

    public function test_the_customer_listing_is_paginated_and_searchable(): void
    {
        $this->actingAsManager();
        $sara = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'name' => 'Sara Ali', 'phone' => '0501110000']);
        Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'name' => 'Omar Nabil', 'phone' => '0502220000']);

        $this->getJson('/api/customers')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['id', 'name', 'phone', 'wallet_balance']], 'total']])
            ->assertJsonPath('data.total', 2);

        $this->getJson('/api/customers?search=Sara')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $sara->getKey());

        $this->getJson('/api/customers?search=0502220000')
            ->assertOk()
            ->assertJsonPath('data.total', 1);
    }

    public function test_the_same_phone_is_allowed_in_a_different_organization(): void
    {
        $this->actingAsManager();
        $this->postJson('/api/customers', ['name' => 'Sara', 'phone' => '0501112222'])->assertCreated();

        // A second tenant may serve the same person.
        Customer::factory()->create(['organization_id' => $this->createOrganization()->getKey(), 'phone' => '0501112222']);
        $this->assertSame(2, Customer::query()->where('phone', '0501112222')->count());
    }

    public function test_a_wallet_topup_flows_through_the_wallet_service(): void
    {
        $this->actingAsManager();
        $customer = Customer::factory()->create(['organization_id' => $this->organization->getKey()]);

        $this->postJson("/api/customers/{$customer->getKey()}/wallet/topup", ['amount' => 150, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.balance', 150);

        $this->assertEquals('150.00', $customer->fresh()->wallet_balance);
    }

    public function test_a_reception_member_cannot_manage_the_catalog(): void
    {
        $reception = $this->createStaff($this->branch, StaffRoleEnum::Reception);
        $this->actingAsStaff($reception);

        // Catalog writes are manager-gated.
        $this->postJson('/api/catalog/categories', ['name' => 'X'])->assertStatus(403);
    }

    public function test_the_till_is_told_whether_a_subscription_can_actually_pay(): void
    {
        $this->actingAsManager();
        $customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        // A priced package that was sold but never collected cannot cover an order.
        $plan = SubscriptionPlan::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'type' => 'piece_quota',
            'price' => 300,
            'quota' => 20,
        ]);
        $subscription = Subscription::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'customer_id' => $customer->getKey(),
            'branch_id' => $this->branch->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'end_at' => now()->addMonth(),
            'remaining_quota' => 20,
        ]);

        $this->getJson("/api/customers/{$customer->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.subscription.id', $subscription->getKey())
            // Uncollected: the counter must not raise an OTP for it.
            ->assertJsonPath('data.subscription.usable', false);

        // A free package needs no collection, so it is usable at once.
        $plan->update(['price' => 0]);

        $this->getJson("/api/customers/{$customer->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.subscription.usable', true);
    }

    public function test_the_customer_listing_carries_no_subscription_key(): void
    {
        $this->actingAsManager();
        Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        // One query per row would be an N+1 for a figure only the counter needs, and
        // a null here would read as "no subscription" rather than "not asked".
        $row = $this->getJson('/api/customers')->assertOk()->json('data.data.0');
        $this->assertArrayNotHasKey('subscription', $row);
    }

    public function test_a_foreign_customer_is_not_visible(): void
    {
        $this->actingAsManager();
        $foreign = Customer::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->getJson("/api/customers/{$foreign->getKey()}")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function actingAsManager(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    private function category(): ServiceCategory
    {
        return ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
    }
}

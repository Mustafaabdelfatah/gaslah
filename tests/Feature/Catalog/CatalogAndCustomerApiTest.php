<?php

namespace Tests\Feature\Catalog;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Service;
use App\Models\ServiceCategory;
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

    public function test_a_customer_is_created_and_the_phone_is_unique_per_organization(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/customers', ['name' => 'Sara', 'phone' => '0501112222'])->assertCreated();

        // The same phone in the same organization is refused.
        $this->postJson('/api/customers', ['name' => 'Other', 'phone' => '0501112222'])->assertStatus(422);
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

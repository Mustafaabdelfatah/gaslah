<?php

namespace Tests\Feature\Market;

use App\Enum\Market\MarketOrderStatusEnum;
use App\Enum\Market\MarketPaymentMethodEnum;
use App\Enum\Market\MarketPaymentStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\MarketProduct;
use App\Models\MarketSupplier;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketBuyerTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private MarketSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        $this->supplier = MarketSupplier::factory()->create(['commission_value' => 10]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
    }

    /*
    |--------------------------------------------------------------------------
    | Browsing
    |--------------------------------------------------------------------------
    */

    public function test_browsing_shows_only_listed_products_from_approved_suppliers(): void
    {
        $listed = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);
        MarketProduct::factory()->inactive()->create(['supplier_id' => $this->supplier->getKey()]);
        MarketProduct::factory()->create([
            'supplier_id' => MarketSupplier::factory()->suspended()->create()->getKey(),
        ]);

        $this->getJson('/api/market/products')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.id', $listed->getKey())
            // The category catalogue rides along for the filter bar.
            ->assertJsonPath('data.categories.0.key', 'detergents');
    }

    public function test_the_buyer_never_sees_the_suppliers_commission_terms(): void
    {
        MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);

        $response = $this->getJson('/api/market/products')->assertOk();

        $supplier = $response->json('data.data.data.0.supplier');
        $this->assertSame(['id', 'name', 'city'], array_keys($supplier));
    }

    public function test_browsing_can_be_filtered_by_category_and_searched_by_name(): void
    {
        MarketProduct::factory()->create([
            'supplier_id' => $this->supplier->getKey(), 'name' => 'صابون سائل', 'category' => 'detergents',
        ]);
        MarketProduct::factory()->create([
            'supplier_id' => $this->supplier->getKey(), 'name' => 'علاقات خشب', 'category' => 'hangers',
        ]);

        $this->getJson('/api/market/products?category=hangers')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.name', 'علاقات خشب');

        $this->getJson('/api/market/products?search=صابون')
            ->assertOk()
            ->assertJsonPath('data.data.total', 1)
            ->assertJsonPath('data.data.data.0.name', 'صابون سائل');
    }

    public function test_a_delisted_product_is_not_reachable_by_id(): void
    {
        $product = MarketProduct::factory()->inactive()->create(['supplier_id' => $this->supplier->getKey()]);

        $this->getJson("/api/market/products/{$product->getKey()}")->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Ordering
    |--------------------------------------------------------------------------
    */

    public function test_placing_an_order_prices_the_basket_and_deducts_commission_from_the_payout(): void
    {
        $first = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey(), 'price' => 100]);
        $second = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey(), 'price' => 25]);

        $response = $this->postJson('/api/market/orders', [
            'items' => [
                ['product_id' => $first->getKey(), 'quantity' => 2],
                ['product_id' => $second->getKey(), 'quantity' => 4],
            ],
            'notes' => 'يسلّم صباحاً',
        ])->assertCreated();

        // 200 + 100 = 300, of which the platform's 10% is 30.
        $response->assertJsonPath('data.subtotal', '300.00')
            ->assertJsonPath('data.total', '300.00')
            ->assertJsonPath('data.status', MarketOrderStatusEnum::Pending->value)
            ->assertJsonPath('data.payment_status', MarketPaymentStatusEnum::Unpaid->value)
            // Deferred is the default: buying on account is the norm here.
            ->assertJsonPath('data.payment_method', MarketPaymentMethodEnum::Deferred->value);

        // The commission split belongs to the supplier's view, not the buyer's.
        $response->assertJsonMissingPath('data.commission_amount')
            ->assertJsonMissingPath('data.supplier_payout');

        $this->assertDatabaseHas('market_orders', [
            'organization_id' => $this->organization->getKey(),
            'commission_amount' => 30,
            'supplier_payout' => 270,
        ]);
    }

    public function test_repeated_lines_for_one_product_are_merged_into_a_single_quantity(): void
    {
        $product = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey(), 'price' => 50]);

        $response = $this->postJson('/api/market/orders', [
            'items' => [
                ['product_id' => $product->getKey(), 'quantity' => 2],
                ['product_id' => $product->getKey(), 'quantity' => 3],
            ],
        ])->assertCreated();

        $response->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.quantity', '5.00')
            ->assertJsonPath('data.subtotal', '250.00');
    }

    public function test_a_basket_spanning_two_suppliers_is_refused(): void
    {
        $mine = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);
        $theirs = MarketProduct::factory()->create([
            'supplier_id' => MarketSupplier::factory()->create()->getKey(),
        ]);

        $this->postJson('/api/market/orders', [
            'items' => [
                ['product_id' => $mine->getKey(), 'quantity' => 1],
                ['product_id' => $theirs->getKey(), 'quantity' => 1],
            ],
        ])->assertStatus(422);
    }

    public function test_a_delisted_product_cannot_be_ordered(): void
    {
        $product = MarketProduct::factory()->inactive()->create(['supplier_id' => $this->supplier->getKey()]);

        $this->postJson('/api/market/orders', [
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_a_fixed_commission_never_exceeds_the_subtotal(): void
    {
        $supplier = MarketSupplier::factory()->fixedCommission(500)->create();
        $product = MarketProduct::factory()->create(['supplier_id' => $supplier->getKey(), 'price' => 30]);

        $this->postJson('/api/market/orders', [
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
        ])->assertCreated();

        // The platform cannot take more than the order was worth, which would put the
        // supplier's payout below zero.
        $this->assertDatabaseHas('market_orders', ['commission_amount' => 30, 'supplier_payout' => 0]);
    }

    /*
    |--------------------------------------------------------------------------
    | Isolation
    |--------------------------------------------------------------------------
    */

    public function test_orders_listing_shows_only_this_laundrys_own_orders(): void
    {
        $product = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);
        $this->postJson('/api/market/orders', [
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
        ])->assertCreated();

        $foreignOrderId = $this->placeOrderForAnotherLaundry($product);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/market/orders')->assertOk()->assertJsonPath('data.total', 1);
        $this->getJson("/api/market/orders/{$foreignOrderId}")->assertNotFound();
    }

    public function test_the_buyer_has_no_route_to_move_an_order_along(): void
    {
        $product = MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);
        $orderId = $this->postJson('/api/market/orders', [
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
        ])->assertCreated()->json('data.id');

        // Lifecycle control is the supplier's alone: there is no buyer-side route at all.
        $this->patchJson("/api/market/orders/{$orderId}", ['status' => 'confirmed'])->assertStatus(405);
    }

    public function test_the_market_is_shut_when_the_plan_does_not_include_it(): void
    {
        $this->organization->forceFill(['feature_overrides' => ['supplier_market' => false]])->save();

        $this->getJson('/api/market/products')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function placeOrderForAnotherLaundry(MarketProduct $product): int
    {
        [, $branch] = $this->createTenant();
        $this->actingAsStaff($this->createStaff($branch, StaffRoleEnum::Cashier));

        return (int) $this->postJson('/api/market/orders', [
            'items' => [['product_id' => $product->getKey(), 'quantity' => 1]],
        ])->assertCreated()->json('data.id');
    }
}

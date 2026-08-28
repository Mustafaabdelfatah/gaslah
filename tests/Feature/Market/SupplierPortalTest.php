<?php

namespace Tests\Feature\Market;

use App\Enum\Market\MarketOrderStatusEnum;
use App\Enum\Market\MarketPaymentMethodEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\MarketOrder;
use App\Models\MarketProduct;
use App\Models\MarketSupplier;
use App\Models\Organization;
use App\Services\Market\MarketOrderService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierPortalTest extends TestCase
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
    }

    /*
    |--------------------------------------------------------------------------
    | Sign-in
    |--------------------------------------------------------------------------
    */

    public function test_a_supplier_signs_in_and_receives_a_supplier_token(): void
    {
        $response = $this->postJson('/api/supplier/auth/login', [
            'email' => strtoupper($this->supplier->email),
            'password' => 'password',
        ])->assertOk();

        $this->assertNotEmpty($response->json('data.token'));
        $response->assertJsonPath('data.supplier.id', $this->supplier->getKey());

        // The hash must never leave the server, whatever serialises the model.
        $response->assertJsonMissingPath('data.supplier.password');

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_type' => MarketSupplier::class,
            'tokenable_id' => $this->supplier->getKey(),
        ]);
    }

    public function test_an_unknown_email_and_a_wrong_password_are_refused_the_same_way(): void
    {
        $unknown = $this->postJson('/api/supplier/auth/login', [
            'email' => 'nobody@example.com', 'password' => 'password',
        ])->assertStatus(401);

        $wrong = $this->postJson('/api/supplier/auth/login', [
            'email' => $this->supplier->email, 'password' => 'not-the-password',
        ])->assertStatus(401);

        // Telling the two apart would turn this into an account-enumeration oracle.
        $this->assertSame($unknown->json('message'), $wrong->json('message'));
    }

    public function test_a_rejected_supplier_cannot_sign_in_but_a_pending_one_can(): void
    {
        $rejected = MarketSupplier::factory()->rejected()->create();
        $this->postJson('/api/supplier/auth/login', [
            'email' => $rejected->email, 'password' => 'password',
        ])->assertForbidden();

        // Pending and suspended accounts still get in: the portal has to be able to show
        // them why they are not selling.
        $pending = MarketSupplier::factory()->pending()->create();
        $this->postJson('/api/supplier/auth/login', [
            'email' => $pending->email, 'password' => 'password',
        ])->assertOk()->assertJsonPath('data.supplier.is_sellable', false);
    }

    public function test_a_staff_token_cannot_act_on_the_supplier_portal(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/supplier/me')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Catalogue
    |--------------------------------------------------------------------------
    */

    public function test_the_portal_shows_the_suppliers_whole_catalogue_including_delisted_products(): void
    {
        MarketProduct::factory()->create(['supplier_id' => $this->supplier->getKey()]);
        MarketProduct::factory()->inactive()->create(['supplier_id' => $this->supplier->getKey()]);
        MarketProduct::factory()->create(['supplier_id' => MarketSupplier::factory()->create()->getKey()]);

        $this->asSupplier()
            ->getJson('/api/supplier/products')
            ->assertOk()
            ->assertJsonPath('data.data.total', 2)
            ->assertJsonPath('data.categories.0.key', 'detergents');
    }

    public function test_a_supplier_lists_and_then_delists_a_product(): void
    {
        $created = $this->asSupplier()->postJson('/api/supplier/products', [
            'name' => 'مسحوق غسيل', 'category' => 'detergents', 'price' => 45.5, 'unit' => 'كيس',
        ])->assertCreated()->assertJsonPath('data.is_active', true);

        $id = $created->json('data.id');

        // Delisting rides on the update endpoint — there is deliberately no second way.
        $this->asSupplier()
            ->putJson("/api/supplier/products/{$id}", ['is_active' => false])
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $this->asSupplier()->postJson('/api/supplier/products', [
            'name' => 'X', 'category' => 'not-a-category', 'price' => 10,
        ])->assertStatus(422);
    }

    public function test_a_supplier_cannot_touch_another_suppliers_product(): void
    {
        $theirs = MarketProduct::factory()->create([
            'supplier_id' => MarketSupplier::factory()->create()->getKey(),
        ]);

        $this->asSupplier()
            ->putJson("/api/supplier/products/{$theirs->getKey()}", ['price' => 1])
            ->assertNotFound();
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */

    public function test_the_supplier_sees_the_commission_split_the_buyer_does_not(): void
    {
        $order = $this->placeOrder(price: 200, quantity: 1);

        $this->asSupplier()
            ->getJson("/api/supplier/orders/{$order->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.commission_amount', '20.00')
            ->assertJsonPath('data.supplier_payout', '180.00')
            ->assertJsonPath('data.buyer', $this->organization->name);
    }

    public function test_an_order_walks_its_lifecycle_and_delivery_is_stamped(): void
    {
        $order = $this->placeOrder();

        foreach ([MarketOrderStatusEnum::Confirmed, MarketOrderStatusEnum::Shipped, MarketOrderStatusEnum::Delivered] as $status) {
            $this->asSupplier()
                ->patchJson("/api/supplier/orders/{$order->getKey()}", ['status' => $status->value])
                ->assertOk()
                ->assertJsonPath('data.status', $status->value);
        }

        $this->assertNotNull($order->refresh()->delivered_at);
    }

    public function test_an_illegal_transition_is_refused(): void
    {
        $order = $this->placeOrder();

        // Pending goes to confirmed or cancelled — never straight to shipped.
        $this->asSupplier()
            ->patchJson("/api/supplier/orders/{$order->getKey()}", ['status' => MarketOrderStatusEnum::Shipped->value])
            ->assertStatus(422);

        // And a delivered order is final.
        $order->forceFill(['status' => MarketOrderStatusEnum::Delivered->value])->save();
        $this->asSupplier()
            ->patchJson("/api/supplier/orders/{$order->getKey()}", ['status' => MarketOrderStatusEnum::Cancelled->value])
            ->assertStatus(422);
    }

    public function test_pending_is_not_a_transition_target(): void
    {
        $order = $this->placeOrder();

        $this->asSupplier()
            ->patchJson("/api/supplier/orders/{$order->getKey()}", ['status' => MarketOrderStatusEnum::Pending->value])
            ->assertStatus(422);
    }

    public function test_a_supplier_cannot_move_another_suppliers_order(): void
    {
        $order = $this->placeOrder();
        $intruder = MarketSupplier::factory()->create();

        $this->asSupplier($intruder)
            ->patchJson("/api/supplier/orders/{$order->getKey()}", ['status' => MarketOrderStatusEnum::Confirmed->value])
            ->assertNotFound();
    }

    public function test_the_summary_counts_only_delivered_orders_as_earnings(): void
    {
        $delivered = $this->placeOrder(price: 100, quantity: 1);
        $this->placeOrder(price: 100, quantity: 1);

        $delivered->forceFill(['status' => MarketOrderStatusEnum::Delivered->value])->save();

        $this->asSupplier()
            ->getJson('/api/supplier/me')
            ->assertOk()
            ->assertJsonPath('data.stats.orders.total', 2)
            ->assertJsonPath('data.stats.orders.pending', 1)
            ->assertJsonPath('data.stats.orders.delivered', 1)
            // Only the delivered one has been earned.
            ->assertJsonPath('data.stats.earnings.delivered_sales', 100)
            ->assertJsonPath('data.stats.earnings.payout', 90);
    }

    public function test_revoking_approval_takes_effect_on_the_next_request(): void
    {
        $token = $this->supplierToken();

        $this->supplier->forceFill(['status' => 'rejected'])->save();

        $this->asNobody();
        $this->withToken($token)->getJson('/api/supplier/me')->assertForbidden();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function asSupplier(?MarketSupplier $supplier = null): self
    {
        $this->asNobody();

        return $this->withToken($this->supplierToken($supplier));
    }

    private function supplierToken(?MarketSupplier $supplier = null): string
    {
        $supplier ??= $this->supplier;

        return $this->postJson('/api/supplier/auth/login', [
            'email' => $supplier->email, 'password' => 'password',
        ])->assertOk()->json('data.token');
    }

    /**
     * Sanctum::actingAs pins a user for the rest of the test, which would shadow a real
     * bearer token. Forgetting the guards puts the request back on the token.
     */
    private function asNobody(): void
    {
        $this->app['auth']->forgetGuards();
    }

    private function placeOrder(float $price = 100, float $quantity = 1): MarketOrder
    {
        $product = MarketProduct::factory()->create([
            'supplier_id' => $this->supplier->getKey(), 'price' => $price,
        ]);

        return app(MarketOrderService::class)->place(
            [['product_id' => $product->getKey(), 'quantity' => $quantity]],
            [
                'organization_id' => $this->organization->getKey(),
                'branch_id' => $this->branch->getKey(),
                'created_by_id' => null,
            ],
            MarketPaymentMethodEnum::Deferred,
        );
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Payments\WalletService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());

        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey()]);
        $this->service = Service::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'product_id' => $product->getKey(),
            'base_price' => 100,
        ]);
    }

    public function test_a_cashier_creates_a_cash_order_through_the_api(): void
    {
        $this->actingAsCashier();

        $response = $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 2]],
            'payment' => ['method' => 'cash'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.grand_total', '230.00');
    }

    public function test_the_wallet_flow_works_end_to_end_through_the_api(): void
    {
        $cashier = $this->actingAsCashier();
        app(WalletService::class)->credit(
            $this->customer, 500, WalletTransactionTypeEnum::Topup, 'Top-up'
        );

        // Request and verify the consent code, then pay with the returned proof.
        $code = $this->postJson('/api/pos/otp/request', ['customer_id' => $this->customer->getKey()])
            ->assertOk()->json('data.dev_code');
        $proof = $this->postJson('/api/pos/otp/verify', ['customer_id' => $this->customer->getKey(), 'code' => $code])
            ->assertOk()->json('data.proof_token');

        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'wallet', 'otp_token' => $proof],
        ])->assertCreated()->assertJsonPath('data.payment_status', 'paid');

        $this->assertEquals('385.00', $this->customer->fresh()->wallet_balance);
    }

    public function test_a_reception_member_cannot_check_out(): void
    {
        $reception = $this->createStaff($this->branch, StaffRoleEnum::Reception);
        $this->actingAsStaff($reception);

        // Checkout requires the pos.checkout permission, which reception lacks.
        $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
        ])->assertStatus(403);
    }

    public function test_the_order_advances_through_its_lifecycle(): void
    {
        $this->actingAsCashier();
        $orderId = $this->postJson('/api/pos/orders', [
            'customer_id' => $this->customer->getKey(),
            'items' => [['service_id' => $this->service->getKey(), 'quantity' => 1]],
            'payment' => ['method' => 'cash'],
        ])->json('data.id');

        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'processing'])->assertOk();
        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'ready'])->assertOk();
        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'delivered'])
            ->assertOk()->assertJsonPath('data.status', 'delivered');

        // An illegal jump is refused.
        $this->patchJson("/api/orders/{$orderId}/status", ['status' => 'processing'])->assertStatus(422);
    }

    private function actingAsCashier(): User
    {
        return $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
    }
}

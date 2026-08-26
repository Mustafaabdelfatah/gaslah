<?php

namespace Tests\Feature\Payments;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\OnlineCharge;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Payments\PayTokenService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OnlinePaymentApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
    }

    /*
    |--------------------------------------------------------------------------
    | Payment link
    |--------------------------------------------------------------------------
    */
    public function test_staff_mint_a_payment_link_for_an_unpaid_order(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $order = $this->order();

        $token = $this->postJson("/api/orders/{$order->getKey()}/payment-link")->assertOk()->json('data.token');

        $this->assertSame($order->getKey(), app(PayTokenService::class)->verify($token, time()));
    }

    public function test_no_link_for_a_cancelled_or_paid_order(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $cancelled = $this->order(['status' => OrderStatusEnum::Cancelled->value]);
        $this->postJson("/api/orders/{$cancelled->getKey()}/payment-link")->assertStatus(422);

        $paid = $this->order(['paid_total' => 115]);
        $this->postJson("/api/orders/{$paid->getKey()}/payment-link")->assertStatus(422);
    }

    /*
    |--------------------------------------------------------------------------
    | Public pay page
    |--------------------------------------------------------------------------
    */
    public function test_the_pay_page_reports_the_amount_due(): void
    {
        $order = $this->order();
        $token = app(PayTokenService::class)->mint($order->getKey(), time());

        $this->getJson("/api/pay/{$token}")
            ->assertOk()
            ->assertJsonPath('data.amount_due', 115)
            ->assertJsonPath('data.paid', false);
    }

    public function test_an_invalid_token_is_404_and_an_expired_one_is_410(): void
    {
        $this->getJson('/api/pay/not~atoken')->assertStatus(404);

        $order = $this->order();
        $expired = app(PayTokenService::class)->mint($order->getKey(), time() - 100000, 1);
        $this->getJson("/api/pay/{$expired}")->assertStatus(410);
    }

    public function test_paying_through_the_stub_gateway_settles_the_order(): void
    {
        $order = $this->order();
        $token = app(PayTokenService::class)->mint($order->getKey(), time());

        $this->postJson("/api/pay/{$token}", ['payment_ref' => 'tx-123'])
            ->assertOk()
            ->assertJsonPath('data.paid', true);

        $payment = Payment::query()->where('order_id', $order->getKey())->first();
        $this->assertTrue($payment->via_gateway);
        $this->assertSame('gateway:tx-123', $payment->reference);
        $this->assertSame(1, OnlineCharge::query()->where('order_id', $order->getKey())->count());
    }

    public function test_paying_twice_with_the_same_reference_is_idempotent(): void
    {
        $order = $this->order();
        $token = app(PayTokenService::class)->mint($order->getKey(), time());

        $this->postJson("/api/pay/{$token}", ['payment_ref' => 'tx-dup'])->assertOk();
        $this->postJson("/api/pay/{$token}", ['payment_ref' => 'tx-dup'])->assertOk();

        // Only one payment, and paid_total not doubled.
        $this->assertSame(1, Payment::query()->where('order_id', $order->getKey())->count());
        $this->assertEquals('115.00', $order->fresh()->paid_total);
    }

    /*
    |--------------------------------------------------------------------------
    | Webhook
    |--------------------------------------------------------------------------
    */
    public function test_the_webhook_fails_closed_without_a_secret(): void
    {
        config()->set('services.moyasar.webhook_secret', null);

        $this->postJson('/api/payments/webhook', ['type' => 'payment_paid', 'data' => ['id' => 'x']])
            ->assertStatus(503);
    }

    public function test_the_webhook_rejects_a_wrong_secret(): void
    {
        config()->set('services.moyasar.webhook_secret', 'whsec');

        $this->postJson('/api/payments/webhook', ['type' => 'payment_paid', 'data' => ['id' => 'x'], 'secret_token' => 'wrong'])
            ->assertStatus(401);
    }

    public function test_the_webhook_settles_a_paid_charge(): void
    {
        config()->set('services.moyasar.webhook_secret', 'whsec');
        config()->set('services.moyasar.secret', 'sk_test');

        $order = $this->order();

        Http::fake([
            '*/payments/tx-hook' => Http::response([
                'id' => 'tx-hook', 'status' => 'paid', 'amount' => 11500, 'currency' => 'SAR',
                'metadata' => ['order_id' => $order->getKey()],
            ]),
        ]);

        $this->postJson('/api/payments/webhook', [
            'type' => 'payment_paid', 'data' => ['id' => 'tx-hook'], 'secret_token' => 'whsec',
        ])->assertOk();

        $this->assertEquals('115.00', $order->fresh()->paid_total);
        $this->assertSame('paid', $order->fresh()->payment_status->value);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function order(array $attributes = []): Order
    {
        return Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'grand_total' => 115,
            'tax_total' => 15,
            ...$attributes,
        ]);
    }
}

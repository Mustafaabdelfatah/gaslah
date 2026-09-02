<?php

namespace Tests\Feature\Orders;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrganizationIntegration;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderNotificationApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_staff_can_send_an_invoice_summary_over_whatsapp(): void
    {
        [$organization, $branch] = $this->createTenant(['name' => 'مغاسل النقاء']);
        $customer = Customer::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'name' => 'خالد',
            'phone' => '0501234567',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
            'order_no' => 'MAIN-1001',
            'grand_total' => 115,
        ]);
        $this->actingAsStaff($this->createStaff($branch, StaffRoleEnum::Cashier));

        $this->postJson("/api/orders/{$order->getKey()}/notify", ['channel' => 'whatsapp'])
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.channel', 'whatsapp')
            ->assertJsonPath('data.status', 'sent');

        $message = WaMessage::query()->sole();
        $this->assertSame($order->getKey(), $message->order_id);
        $this->assertSame('invoice', $message->event_key);
        $this->assertStringContainsString('MAIN-1001', $message->body);
        $this->assertStringContainsString('115.00', $message->body);
    }

    public function test_sms_is_blocked_instead_of_being_misrouted_to_whatsapp(): void
    {
        [$organization, $branch] = $this->createTenant();
        $customer = Customer::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'phone' => '0501234567',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
        $this->actingAsStaff($this->createStaff($branch));

        $this->postJson("/api/orders/{$order->getKey()}/notify", ['channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('data.sent', false)
            ->assertJsonPath('data.channel', 'sms')
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.reason', __('api.sms_not_enabled'));

        $this->assertSame('sms', WaMessage::query()->sole()->channel);
    }

    public function test_a_configured_sms_uses_the_sms_transport(): void
    {
        Http::fake(['https://sms.example.test/*' => Http::response(['message_id' => 'sms-1'])]);
        config()->set([
            'services.sms.enabled' => true,
            'services.sms.url' => 'https://sms.example.test/messages',
        ]);

        [$organization, $branch] = $this->createTenant();
        OrganizationIntegration::query()->create([
            'organization_id' => $organization->getKey(),
            'messaging_enabled' => true,
            'sms_enabled' => true,
            'sms_api_key' => 'secret',
            'sms_sender' => 'GASLAH',
        ]);
        $customer = Customer::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'phone' => '0501234567',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
        $this->actingAsStaff($this->createStaff($branch));

        $this->postJson("/api/orders/{$order->getKey()}/notify", ['channel' => 'sms'])
            ->assertOk()
            ->assertJsonPath('data.sent', true)
            ->assertJsonPath('data.status', 'sent');

        Http::assertSent(fn ($request) => $request->url() === 'https://sms.example.test/messages'
            && $request['sender'] === 'GASLAH'
            && $request['recipients'] === ['0501234567']);
    }

    public function test_an_order_outside_the_staff_scope_is_not_disclosed(): void
    {
        [, $branch] = $this->createTenant();
        [$foreignOrganization, $foreignBranch] = $this->createTenant();
        $foreignCustomer = Customer::factory()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
        ]);
        $foreignOrder = Order::factory()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'customer_id' => $foreignCustomer->getKey(),
        ]);
        $this->actingAsStaff($this->createStaff($branch));

        $this->postJson("/api/orders/{$foreignOrder->getKey()}/notify", ['channel' => 'whatsapp'])
            ->assertNotFound();
    }

    public function test_a_customer_without_a_phone_gets_a_clear_refusal(): void
    {
        [$organization, $branch] = $this->createTenant();
        $customer = Customer::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'phone' => '',
        ]);
        $order = Order::factory()->create([
            'organization_id' => $organization->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
        ]);
        $this->actingAsStaff($this->createStaff($branch));

        $this->postJson("/api/orders/{$order->getKey()}/notify", ['channel' => 'whatsapp'])
            ->assertUnprocessable()
            ->assertJsonPath('message', __('api.customer_phone_missing'));
    }
}

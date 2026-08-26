<?php

namespace Tests\Feature\Messaging;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\InventoryItem;
use App\Models\Order;
use App\Models\Organization;
use App\Models\OrgAnnouncement;
use App\Models\Unit;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AlertsAnnouncementsApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
    }

    public function test_alerts_derive_the_six_groups(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        // A late order (paid, so it does not also count as unpaid).
        $this->order(['status' => OrderStatusEnum::Received->value, 'due_at' => now()->subDay(), 'payment_status' => PaymentStatusEnum::Paid->value, 'grand_total' => 115, 'paid_total' => 115]);
        // An unpaid order.
        $this->order(['payment_status' => PaymentStatusEnum::Unpaid->value, 'grand_total' => 100, 'paid_total' => 0]);
        // A portal delivery request.
        DeliveryRequest::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'customer_id' => $this->customer->getKey(), 'source' => 'portal', 'status' => 'requested']);
        // A low-stock item.
        $unit = Unit::factory()->create(['organization_id' => $this->organization->getKey()]);
        InventoryItem::factory()->low()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'unit_id' => $unit->getKey()]);

        $response = $this->getJson('/api/alerts')->assertOk();
        $groups = collect($response->json('data.groups'))->keyBy('key');

        $this->assertCount(6, $groups);
        $this->assertSame(1, $groups['late']['count']);
        $this->assertSame(1, $groups['unpaid']['count']);
        $this->assertSame(1, $groups['portal_delivery']['count']);
        $this->assertSame(1, $groups['low_stock']['count']);
        $this->assertEquals(100, $groups['unpaid']['amount']);
    }

    public function test_the_notification_log_hides_otp(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        WaMessage::query()->create(['organization_id' => $this->organization->getKey(), 'customer_id' => $this->customer->getKey(), 'to_phone' => '0501234567', 'category' => 'authentication', 'event_key' => 'otp', 'body' => 'code 9999', 'status' => 'sent']);

        $response = $this->getJson('/api/notifications-log')->assertOk();
        $this->assertStringNotContainsString('9999', json_encode($response->json('data')));
    }

    public function test_announcements_are_manager_gated_and_visible_in_the_portal(): void
    {
        // Cashier cannot create.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));
        $this->postJson('/api/announcements', ['title' => 'Offer', 'body' => 'x'])->assertStatus(403);

        // Manager creates one active + one inactive.
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->postJson('/api/announcements', ['title' => 'Eid Offer', 'body' => 'خصم كبير'])->assertCreated();
        OrgAnnouncement::query()->create(['organization_id' => $this->organization->getKey(), 'title' => 'Hidden', 'body' => 'x', 'is_active' => false]);

        // The customer portal shows only the active one.
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/portal/announcements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Eid Offer');
    }

    private function order(array $attributes): Order
    {
        return Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            ...$attributes,
        ]);
    }
}

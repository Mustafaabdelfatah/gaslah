<?php

namespace Tests\Feature\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\AutomationSetting;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\WaMessage;
use App\Services\Orders\AutomationSweeper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomationApiTest extends TestCase
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

    public function test_the_sweeper_advances_an_aged_order_and_notifies(): void
    {
        AutomationSetting::query()->create(['organization_id' => $this->organization->getKey(), 'enabled' => true]);

        $old = $this->order(hoursAgo: 4);   // beyond the 180-min default
        $recent = $this->order(hoursAgo: 0);

        $result = app(AutomationSweeper::class)->sweep();

        $this->assertSame(1, $result['advanced']);
        $this->assertSame(OrderStatusEnum::Ready, $old->fresh()->status);
        $this->assertSame(OrderStatusEnum::Received, $recent->fresh()->status);
        // The order-ready message was fired for the advanced order.
        $this->assertSame(1, WaMessage::query()->where('order_id', $old->getKey())->count());
    }

    public function test_the_sweeper_skips_organizations_with_automation_off(): void
    {
        AutomationSetting::query()->create(['organization_id' => $this->organization->getKey(), 'enabled' => false]);
        $this->order(hoursAgo: 4);

        $this->assertSame(0, app(AutomationSweeper::class)->sweep()['advanced']);
    }

    public function test_only_the_general_manager_can_change_automation(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));
        $this->putJson('/api/automation', ['enabled' => true])->assertStatus(403);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->putJson('/api/automation', ['enabled' => true])->assertOk()->assertJsonPath('data.enabled', true);
    }

    private function order(int $hoursAgo): Order
    {
        $order = Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'status' => OrderStatusEnum::Received->value,
        ]);

        $order->forceFill(['created_at' => now()->subHours($hoursAgo)])->save();

        return $order;
    }
}

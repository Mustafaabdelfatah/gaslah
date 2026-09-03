<?php

namespace Tests\Feature\Orders;

use App\Enum\Catalog\ServiceTypeEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\AutomationSetting;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
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

    public function test_the_settings_endpoint_exposes_and_saves_each_service_type_delay(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/automation')
            ->assertOk()
            ->assertJsonPath('data.delays.default.normal', 180)
            ->assertJsonPath('data.delays.default.express', 30)
            ->assertJsonPath('data.delays.service_types.wash.normal', 0)
            ->assertJsonPath('data.delays.service_types.iron.normal', 0)
            ->assertJsonPath('data.delays.service_types.wash_iron.normal', 0);

        $this->putJson('/api/automation', [
            'enabled' => true,
            'delays' => [
                'default' => ['normal' => 180, 'express' => 30],
                'service_types' => [
                    'wash' => ['normal' => 120, 'express' => 20],
                    'iron' => ['normal' => 90, 'express' => 15],
                    'wash_iron' => ['normal' => 240, 'express' => 45],
                ],
            ],
        ])->assertOk();

        $this->getJson('/api/automation')
            ->assertOk()
            ->assertJsonPath('data.delays.service_types.wash.normal', 120)
            ->assertJsonPath('data.delays.service_types.iron.express', 15)
            ->assertJsonPath('data.delays.service_types.wash_iron.normal', 240);
    }

    public function test_a_partial_automation_update_does_not_erase_service_type_overrides(): void
    {
        AutomationSetting::query()->create([
            'organization_id' => $this->organization->getKey(),
            'enabled' => true,
            'delays' => [
                'default' => ['normal' => 180, 'express' => 30],
                'service_types' => [
                    'wash' => ['normal' => 120, 'express' => 20],
                    'iron' => ['normal' => 90, 'express' => 15],
                ],
            ],
        ]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->putJson('/api/automation', [
            'enabled' => false,
            'delays' => ['default' => ['normal' => 200]],
        ])->assertOk();

        $this->getJson('/api/automation')
            ->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.delays.default.normal', 200)
            ->assertJsonPath('data.delays.default.express', 30)
            ->assertJsonPath('data.delays.service_types.wash.normal', 120)
            ->assertJsonPath('data.delays.service_types.iron.express', 15);
    }

    public function test_the_sweeper_uses_the_delay_for_the_orders_service_type(): void
    {
        $setting = AutomationSetting::query()->create([
            'organization_id' => $this->organization->getKey(),
            'enabled' => true,
            'delays' => [
                'default' => ['normal' => 180, 'express' => 30],
                'service_types' => [
                    'wash' => ['normal' => 300, 'express' => 20],
                ],
            ],
        ]);
        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
        ]);
        $service = Service::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'product_id' => $product->getKey(),
            'service_type' => ServiceTypeEnum::Wash->value,
        ]);
        $order = $this->order(hoursAgo: 4);
        $order->items()->create([
            'service_id' => $service->getKey(),
            'quantity' => 1,
            'unit_price' => 10,
            'line_total' => 10,
        ]);

        $this->assertSame(0, app(AutomationSweeper::class)->sweep()['advanced']);
        $this->assertSame(OrderStatusEnum::Received, $order->fresh()->status);

        $setting->forceFill([
            'delays' => [
                'default' => ['normal' => 180, 'express' => 30],
                'service_types' => ['wash' => ['normal' => 120, 'express' => 20]],
            ],
        ])->save();

        $this->assertSame(1, app(AutomationSweeper::class)->sweep()['advanced']);
        $this->assertSame(OrderStatusEnum::Ready, $order->fresh()->status);
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

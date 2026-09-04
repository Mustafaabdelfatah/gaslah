<?php

namespace Tests\Feature\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Reports\ReportRangeService;
use App\Services\Reports\ReportService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportApiTest extends TestCase
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
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);

        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey(), 'name' => 'Thobe']);
        $this->service = Service::factory()->create([
            'organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey(),
            'product_id' => $product->getKey(), 'name' => 'Wash',
        ]);
    }

    public function test_the_sales_summary_excludes_cancelled_orders(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->order(['grand_total' => 100, 'paid_total' => 100]);
        $this->order(['grand_total' => 200, 'paid_total' => 50]);
        $this->order(['grand_total' => 999, 'status' => OrderStatusEnum::Cancelled->value]);

        $this->getJson('/api/reports/sales')
            ->assertOk()
            ->assertJsonPath('data.summary.orders', 2)
            ->assertJsonPath('data.summary.revenue', 300)
            ->assertJsonPath('data.summary.collected', 150)
            ->assertJsonPath('data.summary.outstanding', 150);
    }

    public function test_the_report_screen_can_load_each_access_level_in_one_request(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $order = $this->order(['grand_total' => 200, 'paid_total' => 100]);
        $order->items()->create(['service_id' => $this->service->getKey(), 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);

        $this->getJson('/api/reports/overview')
            ->assertOk()
            ->assertJsonPath('data.sales.summary.orders', 1)
            ->assertJsonPath('data.top_products.services.0.name', 'Wash');

        $this->getJson('/api/reports/management-overview?limit=5')
            ->assertOk()
            ->assertJsonPath('data.top_customers.0.customer_id', $this->customer->getKey())
            ->assertJsonPath('data.cancellation_rate.total_orders', 1);
    }

    public function test_the_payment_breakdown_splits_online_and_subscription(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        // Cash payment.
        $cash = $this->order(['grand_total' => 100, 'paid_total' => 100]);
        $cash->payments()->create(['method' => PaymentMethodEnum::Cash->value, 'amount' => 100]);

        // Online (gateway card) payment.
        $online = $this->order(['grand_total' => 100, 'paid_total' => 100]);
        $online->payments()->create(['method' => PaymentMethodEnum::Card->value, 'amount' => 100, 'reference' => 'gateway:t1', 'via_gateway' => true]);

        // Subscription-covered order: paid, no payment row.
        $this->order(['grand_total' => 100, 'paid_total' => 100, 'subscription_id' => 1]);

        $response = $this->getJson('/api/reports/sales')->assertOk();

        $names = collect($response->json('data.by_payment_method'))->pluck('name');
        $this->assertTrue($names->contains('نقدي'));
        $this->assertTrue($names->contains('أونلاين'));
        $this->assertTrue($names->contains('اشتراك'));

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(ReportService::class)->sales(
            [$this->branch->getKey()],
            app(ReportRangeService::class)->resolve(null, null),
        );
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, count($queries), 'The sales report must stay within four aggregate queries.');
    }

    public function test_top_products_aggregates_line_items(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $order = $this->order(['grand_total' => 200]);
        $order->items()->create(['service_id' => $this->service->getKey(), 'quantity' => 2, 'unit_price' => 100, 'line_total' => 200]);

        $this->getJson('/api/reports/top-products')
            ->assertOk()
            ->assertJsonPath('data.services.0.name', 'Wash')
            ->assertJsonPath('data.services.0.revenue', 200)
            ->assertJsonPath('data.products.0.name', 'Thobe');
    }

    public function test_top_customers_aggregates_per_customer(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->order(['grand_total' => 300, 'paid_total' => 300]);
        $this->order(['grand_total' => 100, 'paid_total' => 40]);

        $response = $this->getJson('/api/reports/top-customers')->assertOk();
        $this->assertSame($this->customer->getKey(), $response->json('data.0.customer_id'));
        $this->assertSame(2, $response->json('data.0.orders_count'));
        $this->assertEquals(400, $response->json('data.0.revenue'));
    }

    public function test_the_cancellation_rate_counts_every_status(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->order([]);
        $this->order([]);
        $this->order(['status' => OrderStatusEnum::Cancelled->value]);

        $this->getJson('/api/reports/cancellation-rate')
            ->assertOk()
            ->assertJsonPath('data.total_orders', 3)
            ->assertJsonPath('data.cancelled_orders', 1)
            ->assertJsonPath('data.rate', 33.33);
    }

    public function test_reports_are_permission_gated(): void
    {
        $cashier = $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        $this->actingAsStaff($cashier);

        // Cashier lacks reports.view.
        $this->getJson('/api/reports/sales')->assertStatus(403);
        // And is not a manager.
        $this->getJson('/api/reports/top-customers')->assertStatus(403);
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

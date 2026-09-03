<?php

namespace Tests\Feature\Reports;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsDashboardApiTest extends TestCase
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
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    /*
    |--------------------------------------------------------------------------
    | Analytics
    |--------------------------------------------------------------------------
    */
    public function test_analytics_summarises_the_period(): void
    {
        $this->order(['grand_total' => 100]);
        $this->order(['grand_total' => 300]);

        $response = $this->getJson('/api/analytics')->assertOk();
        $this->assertEquals(400, $response->json('data.summary.revenue'));
        $this->assertSame(2, $response->json('data.summary.orders'));
        $this->assertEquals(200, $response->json('data.summary.aov'));
        // The heatmap is a 7x24 grid.
        $this->assertCount(7, $response->json('data.heatmap.grid'));
        $this->assertCount(24, $response->json('data.heatmap.grid.0'));
    }

    public function test_analytics_requires_the_feature(): void
    {
        $this->organization->update(['feature_overrides' => ['analytics' => false]]);

        $this->getJson('/api/analytics')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    */
    public function test_the_dashboard_reports_today_and_stages(): void
    {
        $this->order(['grand_total' => 100, 'paid_total' => 100, 'payment_status' => PaymentStatusEnum::Paid->value, 'status' => OrderStatusEnum::Received->value]);
        $this->order(['grand_total' => 50, 'paid_total' => 50, 'payment_status' => PaymentStatusEnum::Paid->value, 'status' => OrderStatusEnum::Ready->value]);
        $this->order(['grand_total' => 80, 'payment_status' => PaymentStatusEnum::Unpaid->value, 'paid_total' => 0]);

        $response = $this->getJson('/api/dashboard')->assertOk();
        $this->assertSame(3, $response->json('data.orders_today'));
        $this->assertEquals(230, $response->json('data.revenue_today'));
        $this->assertSame(1, $response->json('data.stages.ready'));
        $this->assertGreaterThanOrEqual(1, $response->json('data.unpaid_count'));
        $this->assertCount(7, $response->json('data.weekly'));
    }

    public function test_the_dashboard_lists_only_active_baskets_with_a_real_balance(): void
    {
        $unpaid = $this->order([
            'grand_total' => 100,
            'paid_total' => 0,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
        ]);
        $partial = $this->order([
            'grand_total' => 120,
            'paid_total' => 20,
            'payment_status' => PaymentStatusEnum::Partial->value,
        ]);
        $deferred = $this->order([
            'grand_total' => 80,
            'paid_total' => 30,
            'payment_status' => PaymentStatusEnum::Deferred->value,
        ]);

        $this->order([
            'grand_total' => 100,
            'paid_total' => 100,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
        ]);
        $this->order([
            'grand_total' => 90,
            'paid_total' => 0,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
            'status' => OrderStatusEnum::Cancelled->value,
        ]);
        $this->order([
            'grand_total' => 70,
            'paid_total' => 0,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
            'archived_at' => now(),
        ]);

        $response = $this->getJson('/api/dashboard')->assertOk();

        $response
            ->assertJsonPath('data.unpaid_count', 3)
            ->assertJsonPath('data.unpaid_amount', 250)
            ->assertJsonCount(3, 'data.unpaid');

        $rows = collect($response->json('data.unpaid'))->keyBy('id');
        $this->assertEquals(100, $rows->get($unpaid->getKey())['remaining']);
        $this->assertEquals(100, $rows->get($partial->getKey())['remaining']);
        $this->assertEquals(50, $rows->get($deferred->getKey())['remaining']);
    }

    public function test_the_dashboard_branch_filter_is_validated(): void
    {
        $foreign = Branch::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->getJson('/api/dashboard?branch='.$foreign->getKey())->assertStatus(403);
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

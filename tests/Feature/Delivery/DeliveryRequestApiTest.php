<?php

namespace Tests\Feature\Delivery;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Delivery\DeliveryRequestService;
use App\Services\Delivery\DeliverySettingsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DeliveryRequestApiTest extends TestCase
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

        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    public function test_creating_a_request_auto_assigns_the_only_active_driver(): void
    {
        $driver = $this->driver();

        $response = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'Street 1',
        ])->assertCreated();

        $this->assertSame('assigned', $response->json('data.0.status'));
        $this->assertSame($driver->getKey(), (int) $response->json('data.0.driver_id'));
    }

    public function test_the_request_listing_renders(): void
    {
        $this->driver();
        $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'Street 1',
        ])->assertCreated();

        // Building the collection maps the resource in with the item's key as a
        // second argument, which a second constructor parameter would reject.
        $this->getJson('/api/delivery/requests')
            ->assertOk()
            ->assertJsonPath('data.data.0.type', 'pickup')
            ->assertJsonPath('data.data.0.next_statuses', ['picked_up', 'cancelled'])
            // Signed photo URLs belong to the detail view only.
            ->assertJsonMissingPath('data.data.0.pickup_photo_signed_url');
    }

    public function test_the_dispatch_listing_includes_the_linked_invoice_zone_and_map(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $zone = DeliveryZone::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);
        $order = Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
        ]);

        $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(),
            'type' => 'delivery',
            'address' => 'Riyadh',
            'zone_id' => $zone->getKey(),
            'order_id' => $order->getKey(),
            'lat' => 24.7136,
            'lng' => 46.6753,
        ])->assertCreated();

        $this->getJson('/api/delivery/requests')
            ->assertOk()
            ->assertJsonPath('data.data.0.zone.id', $zone->getKey())
            ->assertJsonPath('data.data.0.order.id', $order->getKey())
            ->assertJsonPath('data.data.0.next_statuses', ['assigned', 'cancelled'])
            ->assertJsonPath(
                'data.data.0.maps_link',
                'https://www.google.com/maps/search/?api=1&query=24.7136000,46.6753000',
            );
    }

    public function test_both_creates_a_pickup_and_a_delivery(): void
    {
        $this->driver();

        $response = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'both', 'address' => 'Street 1',
        ])->assertCreated();

        $this->assertCount(2, $response->json('data'));
        $types = collect($response->json('data'))->pluck('type')->sort()->values()->all();
        $this->assertSame(['delivery', 'pickup'], $types);
    }

    public function test_a_zone_fee_overrides_self_pricing(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['self' => ['flatFee' => 10]]);
        $zone = DeliveryZone::factory()->create([
            'organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'fee' => 35,
        ]);

        $response = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X', 'zone_id' => $zone->getKey(),
        ])->assertCreated();

        $this->assertEquals('35.00', $response->json('data.0.fee'));
    }

    public function test_manual_assignment_leaves_the_request_unassigned(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $driver = $this->driver();

        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->assertCreated()->json('data.0.id');

        $this->assertSame('requested', DeliveryRequest::query()->find($id)->status->value);

        $this->patchJson("/api/delivery/requests/{$id}", ['driver_id' => $driver->getKey()])
            ->assertOk()->assertJsonPath('data.status', 'assigned');
    }

    public function test_assigning_an_ineligible_driver_is_refused(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $foreign = Driver::factory()->create([
            'organization_id' => $this->createOrganization()->getKey(),
            'branch_id' => Branch::factory()->create()->getKey(),
        ]);

        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->json('data.0.id');

        $this->patchJson("/api/delivery/requests/{$id}", ['driver_id' => $foreign->getKey()])->assertStatus(404);
    }

    public function test_the_status_flow_is_enforced(): void
    {
        $this->driver();
        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->json('data.0.id');

        // A pickup ends at at_facility.
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'picked_up'])->assertOk();
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'at_facility'])
            ->assertOk()->assertJsonPath('data.status', 'at_facility');

        // A terminal state accepts no further transition.
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'picked_up'])->assertStatus(422);
    }

    public function test_delivery_cannot_be_completed_without_the_required_photo(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['photoProof' => true]]);
        $this->driver();

        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'delivery', 'address' => 'X',
        ])->json('data.0.id');

        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'picked_up'])->assertOk();
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'out_for_delivery'])->assertOk();
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'delivered'])->assertStatus(422);

        // With a proof photo on file it completes.
        DeliveryRequest::query()->whereKey($id)->update(['delivery_photo_url' => 'proof.jpg']);
        $this->patchJson("/api/delivery/requests/{$id}", ['status' => 'delivered'])->assertOk();
    }

    public function test_external_assignment_requires_the_integration_method(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'delivery', 'address' => 'X',
        ])->json('data.0.id');

        // integration is not available by default, so it stays off.
        $this->patchJson("/api/delivery/requests/{$id}", ['external_provider' => 'Mrsool'])->assertStatus(422);
    }

    public function test_inventory_creates_one_invoice_and_is_idempotent(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $service = $this->service();
        $id = $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->json('data.0.id');

        $this->postJson("/api/delivery/requests/{$id}/inventory", [
            'items' => [['service_id' => $service->getKey(), 'quantity' => 2]],
        ])->assertOk();

        $request = DeliveryRequest::query()->find($id);
        $this->assertNotNull($request->order_id);

        // A second inventory is refused.
        $this->postJson("/api/delivery/requests/{$id}/inventory", [
            'items' => [['service_id' => $service->getKey(), 'quantity' => 1]],
        ])->assertStatus(422);
    }

    public function test_stats_return_the_dashboard_counts(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['manualAssign' => true]]);
        $this->postJson('/api/delivery/requests', [
            'customer_id' => $this->customer->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->assertCreated();

        $this->getJson('/api/delivery/stats')
            ->assertOk()
            ->assertJsonPath('data.pending_assignment', 1);
    }

    public function test_delivery_stats_keep_a_bounded_query_count_as_trip_volume_grows(): void
    {
        DeliveryRequest::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
        ]);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(DeliveryRequestService::class)->stats([$this->branch->getKey()]);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThanOrEqual(4, $queries);
    }

    public function test_stats_match_the_live_delivery_and_full_service_averages(): void
    {
        $order = Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
        ]);
        $pickupStarted = Carbon::now()->subHours(4);

        DeliveryRequest::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'order_id' => $order->getKey(),
            'type' => 'pickup',
            'status' => 'at_facility',
            'created_at' => $pickupStarted,
        ]);
        DeliveryRequest::factory()->delivery()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'order_id' => $order->getKey(),
            'status' => 'delivered',
            'assigned_at' => Carbon::now()->subMinutes(90),
            'completed_at' => Carbon::now(),
        ]);

        // A completed request in another branch must not alter either average.
        [$foreignOrganization, $foreignBranch] = $this->createTenant();
        $foreignCustomer = Customer::factory()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
        ]);
        DeliveryRequest::factory()->delivery()->create([
            'organization_id' => $foreignOrganization->getKey(),
            'branch_id' => $foreignBranch->getKey(),
            'customer_id' => $foreignCustomer->getKey(),
            'status' => 'delivered',
            'assigned_at' => Carbon::now()->subDays(10),
            'completed_at' => Carbon::now(),
        ]);

        $this->getJson('/api/delivery/stats')
            ->assertOk()
            ->assertJsonPath('data.avg_delivery_minutes', 90)
            ->assertJsonPath('data.avg_service_minutes', 240)
            ->assertJsonPath('data.window_days', 90);
    }

    public function test_a_foreign_customer_cannot_receive_a_request(): void
    {
        $foreign = Customer::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->postJson('/api/delivery/requests', [
            'customer_id' => $foreign->getKey(), 'type' => 'pickup', 'address' => 'X',
        ])->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function driver(): Driver
    {
        return Driver::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);
    }

    private function service(): Service
    {
        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey()]);

        return Service::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'category_id' => $category->getKey(),
            'product_id' => $product->getKey(),
            'base_price' => 100,
        ]);
    }
}

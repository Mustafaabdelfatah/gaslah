<?php

namespace Tests\Feature\Orders;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderListApiTest extends TestCase
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

        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
        ]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));
    }

    public function test_the_listing_is_paginated_and_shaped_by_the_resource(): void
    {
        $order = $this->makeOrder();

        $this->getJson('/api/orders')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data' => [['id', 'order_no', 'status', 'grand_total', 'remaining']], 'total', 'per_page']])
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $order->getKey())
            // The resource reports what is still owed rather than leaving it to the client.
            ->assertJsonPath('data.data.0.remaining', 115);
    }

    public function test_the_listing_filters_by_status_and_payment_status(): void
    {
        $this->makeOrder(['status' => 'received', 'payment_status' => 'unpaid']);
        $paid = $this->makeOrder(['status' => 'ready', 'payment_status' => 'paid', 'paid_total' => 115]);

        $this->getJson('/api/orders?status=ready')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $paid->getKey());

        $this->getJson('/api/orders?payment_status=unpaid')
            ->assertOk()
            ->assertJsonPath('data.total', 1);

        // Several statuses at once, the way a board view asks for them.
        $this->getJson('/api/orders?status=received,ready')
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_the_listing_searches_by_ticket_and_by_customer(): void
    {
        $order = $this->makeOrder(['order_no' => 'ORD-9001']);
        $this->makeOrder(['order_no' => 'ORD-9002']);

        $this->getJson('/api/orders?search=9001')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.id', $order->getKey());

        $this->getJson('/api/orders?search='.urlencode($this->customer->name))
            ->assertOk()
            ->assertJsonPath('data.total', 2);
    }

    public function test_an_order_from_another_tenant_is_not_found(): void
    {
        [, $otherBranch] = $this->createTenant();
        $foreign = $this->makeOrder(['branch_id' => $otherBranch->getKey()]);

        $this->getJson("/api/orders/{$foreign->getKey()}")->assertNotFound();
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function makeOrder(array $attributes = []): Order
    {
        static $sequence = 0;
        $sequence++;

        return Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'order_no' => 'ORD-'.(8000 + $sequence),
            'barcode' => 'BC-'.(8000 + $sequence),
            'subtotal' => 100,
            'tax_total' => 15,
            'grand_total' => 115,
            ...$attributes,
        ]);
    }
}

<?php

namespace Tests\Feature\Orders;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderCollectionApiTest extends TestCase
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

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));
    }

    public function test_a_partial_collection_moves_the_order_to_partial(): void
    {
        $order = $this->debt();

        $this->postJson("/api/orders/{$order->getKey()}/payments", ['amount' => 40, 'method' => 'cash'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'partial')
            ->assertJsonPath('data.paid_total', '40.00')
            ->assertJsonPath('data.remaining', 75);
    }

    public function test_collecting_the_remaining_settles_the_order(): void
    {
        $order = $this->debt(['paid_total' => 100, 'payment_status' => 'partial']);

        $this->postJson("/api/orders/{$order->getKey()}/payments", ['amount' => 15, 'method' => 'card'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid')
            ->assertJsonPath('data.remaining', 0);
    }

    public function test_a_deferred_debt_is_collectable(): void
    {
        // آجل is the whole reason this endpoint exists: the customer comes back to clear it.
        $order = $this->debt(['payment_status' => 'deferred']);

        $this->postJson("/api/orders/{$order->getKey()}/payments", ['amount' => 115, 'method' => 'transfer'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'paid');
    }

    public function test_more_than_the_debt_is_refused_not_clamped(): void
    {
        $order = $this->debt(['paid_total' => 100, 'payment_status' => 'partial']);

        // A figure larger than the debt is a typo; quietly taking less would hide it.
        $this->postJson("/api/orders/{$order->getKey()}/payments", ['amount' => 16, 'method' => 'cash'])
            ->assertStatus(422);

        $this->assertSame('100.00', $order->fresh()->paid_total);
    }

    public function test_a_settled_or_cancelled_order_takes_no_money(): void
    {
        $paid = $this->debt(['paid_total' => 115, 'payment_status' => 'paid']);
        $cancelled = $this->debt(['status' => 'cancelled']);

        $this->postJson("/api/orders/{$paid->getKey()}/payments", ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(422);
        $this->postJson("/api/orders/{$cancelled->getKey()}/payments", ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(422);
    }

    public function test_wallet_is_not_a_counter_method_here(): void
    {
        // A wallet draw needs the customer's OTP and belongs to the POS flow.
        $this->postJson("/api/orders/{$this->debt()->getKey()}/payments", ['amount' => 10, 'method' => 'wallet'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('method');
    }

    public function test_the_collection_lands_in_the_books(): void
    {
        $order = $this->debt();

        $this->postJson("/api/orders/{$order->getKey()}/payments", ['amount' => 50, 'method' => 'cash'])->assertOk();

        // Dr Cash / Cr AR, referenced to the payment row.
        $paymentId = $order->payments()->value('id');
        $this->assertTrue(
            JournalEntry::query()->where('ref_type', 'Payment')->where('ref_id', $paymentId)->exists(),
        );
    }

    public function test_a_foreign_order_is_not_found(): void
    {
        $foreign = Order::factory()->create([
            'organization_id' => $this->createOrganization()->getKey(),
            'order_no' => 'X-1',
            'barcode' => 'XB-1',
        ]);

        $this->postJson("/api/orders/{$foreign->getKey()}/payments", ['amount' => 10, 'method' => 'cash'])
            ->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function debt(array $attributes = []): Order
    {
        static $sequence = 0;
        $sequence++;

        return Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'order_no' => 'COL-'.(9000 + $sequence),
            'barcode' => 'CB-'.(9000 + $sequence),
            'subtotal' => 100,
            'tax_total' => 15,
            'grand_total' => 115,
            ...$attributes,
        ]);
    }
}

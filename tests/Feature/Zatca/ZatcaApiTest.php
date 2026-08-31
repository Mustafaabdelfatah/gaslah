<?php

namespace Tests\Feature\Zatca;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\ZatcaInvoice;
use App\Support\ZatcaPhase2;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ZatcaApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    private Service $service;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant(['vat_number' => '300000000000003', 'tax_rate' => 15]);
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'name' => 'Sara']);

        $category = ServiceCategory::factory()->create(['organization_id' => $this->organization->getKey()]);
        $product = Product::factory()->create(['organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey()]);
        $this->service = Service::factory()->create([
            'organization_id' => $this->organization->getKey(), 'category_id' => $category->getKey(),
            'product_id' => $product->getKey(), 'name' => 'Wash', 'base_price' => 100,
        ]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 1
    |--------------------------------------------------------------------------
    */
    public function test_the_phase_one_invoice_carries_a_qr_and_totals(): void
    {
        $order = $this->order();

        $response = $this->getJson("/api/orders/{$order->getKey()}/invoice")->assertOk();

        $qr = $response->json('data.qr');
        $this->assertNotEmpty($qr);
        // Tag 1 (seller name) is present in the decoded TLV payload.
        $this->assertStringContainsString($this->organization->name, base64_decode($qr));
        $this->assertEquals(115, $response->json('data.grand_total'));
    }

    /*
    |--------------------------------------------------------------------------
    | Phase 2
    |--------------------------------------------------------------------------
    */
    public function test_generation_requires_vat_registration(): void
    {
        $this->organization->update(['vat_number' => null]);
        $order = $this->order();

        $this->postJson("/api/orders/{$order->getKey()}/zatca-invoice")->assertStatus(422);
    }

    public function test_generation_is_idempotent_and_chains_invoices(): void
    {
        $orderA = $this->order();
        $first = $this->postJson("/api/orders/{$orderA->getKey()}/zatca-invoice")->assertCreated();
        $this->assertSame(1, $first->json('data.icv'));
        $this->assertSame(ZatcaPhase2::GENESIS_PIH, $first->json('data.pih'));

        // A second call for the same order returns the same invoice, not a new one.
        $this->postJson("/api/orders/{$orderA->getKey()}/zatca-invoice")
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'));
        $this->assertSame(1, ZatcaInvoice::query()->count());

        // A second order chains: ICV 2, PIH = the first invoice's hash.
        $orderB = $this->order();
        $second = $this->postJson("/api/orders/{$orderB->getKey()}/zatca-invoice")->assertCreated();
        $this->assertSame(2, $second->json('data.icv'));
        $this->assertSame($first->json('data.hash'), $second->json('data.pih'));
    }

    public function test_show_returns_the_stored_invoice_or_404(): void
    {
        $order = $this->order();

        $this->getJson("/api/orders/{$order->getKey()}/zatca-invoice")->assertStatus(404);

        $this->postJson("/api/orders/{$order->getKey()}/zatca-invoice")->assertCreated();
        $this->getJson("/api/orders/{$order->getKey()}/zatca-invoice")
            ->assertOk()
            ->assertJsonPath('data.status', 'generated');
    }

    public function test_a_foreign_order_is_not_visible(): void
    {
        $foreign = Order::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->getJson("/api/orders/{$foreign->getKey()}/invoice")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    public function test_the_status_reports_the_chain_and_admits_what_is_not_connected(): void
    {
        $this->postJson("/api/orders/{$this->order()->getKey()}/zatca-invoice")->assertCreated();

        $response = $this->getJson('/api/zatca/status')->assertOk();

        $response->assertJsonPath('data.seller.vat_number', '300000000000003')
            ->assertJsonPath('data.phase_one_ready', true)
            ->assertJsonPath('data.chain.count', 1)
            ->assertJsonPath('data.chain.last_icv', 1)
            // Nothing has been sent to the authority, so nothing claims to have been.
            ->assertJsonPath('data.chain.reported_count', 0)
            ->assertJsonPath('data.onboarded', false);

        // The screen must be able to say *why* it is not connected.
        $this->assertNotEmpty($response->json('data.gaps.onboarding'));
        $this->assertNotEmpty($response->json('data.gaps.signing'));
        $this->assertNotEmpty($response->json('data.gaps.reporting'));
    }

    public function test_the_status_says_phase_one_is_not_ready_without_a_vat_number(): void
    {
        $this->organization->update(['vat_number' => null]);

        // No VAT number means no lawful tax invoice — the QR would be meaningless.
        $this->getJson('/api/zatca/status')
            ->assertOk()
            ->assertJsonPath('data.phase_one_ready', false)
            ->assertJsonPath('data.seller.vat_number', null);
    }

    public function test_a_cashier_cannot_read_the_compliance_position(): void
    {
        $this->app['auth']->forgetGuards();
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/zatca/status')->assertStatus(403);
    }

    private function order(): Order
    {
        $order = Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'subtotal' => 100, 'tax_total' => 15, 'tax_rate' => 15, 'grand_total' => 115,
        ]);

        $order->items()->create([
            'service_id' => $this->service->getKey(),
            'quantity' => 1, 'unit_price' => 100, 'line_total' => 100,
        ]);

        return $order;
    }
}

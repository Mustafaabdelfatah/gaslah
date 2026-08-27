<?php

namespace Tests\Feature\Portal;

use App\Models\Branch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\DeliveryRequest;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Delivery\DeliverySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PortalApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create(['slug' => 'sparkle']);
        $this->branch = Branch::factory()->main()->create(['organization_id' => $this->organization->getKey()]);
        $this->customer = Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'phone' => '0507778888',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    */
    public function test_a_registered_customer_logs_in_and_reads_their_profile(): void
    {
        $code = $this->postJson('/api/portal/auth/request-otp', ['phone' => '0507778888', 'org' => 'sparkle'])
            ->assertOk()->json('data.dev_code');
        $this->assertNotNull($code);

        $token = $this->postJson('/api/portal/auth/verify-otp', ['phone' => '0507778888', 'org' => 'sparkle', 'code' => $code])
            ->assertOk()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/portal/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '0507778888');
    }

    public function test_an_unknown_phone_gets_a_uniform_success_without_a_code(): void
    {
        $this->postJson('/api/portal/auth/request-otp', ['phone' => '0500000000', 'org' => 'sparkle'])
            ->assertOk()
            ->assertJsonPath('data.delivered', true)
            ->assertJsonMissingPath('data.dev_code');
    }

    public function test_an_unknown_organization_does_not_leak(): void
    {
        $this->postJson('/api/portal/auth/request-otp', ['phone' => '0507778888', 'org' => 'nope'])
            ->assertOk()
            ->assertJsonPath('data.sent', false);
    }

    public function test_a_staff_token_cannot_reach_the_portal(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/portal/me')->assertStatus(401);
    }

    /*
    |--------------------------------------------------------------------------
    | Orders
    |--------------------------------------------------------------------------
    */
    public function test_a_customer_sees_only_their_own_orders(): void
    {
        Sanctum::actingAs($this->customer);
        $mine = Order::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'customer_id' => $this->customer->getKey()]);
        $foreign = Order::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);

        $ids = collect($this->getJson('/api/portal/orders')->assertOk()->json('data.data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->getKey()));
        $this->assertCount(1, $ids);

        $this->getJson("/api/portal/orders/{$foreign->getKey()}")->assertStatus(404);
        $this->getJson("/api/portal/orders/{$mine->getKey()}")->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Addresses
    |--------------------------------------------------------------------------
    */
    public function test_setting_a_default_address_clears_the_previous_default(): void
    {
        Sanctum::actingAs($this->customer);
        $first = CustomerAddress::factory()->create(['customer_id' => $this->customer->getKey(), 'is_default' => true]);

        $this->postJson('/api/portal/addresses', ['label' => 'Home', 'is_default' => true])->assertCreated();

        $this->assertFalse($first->fresh()->is_default);
        $this->assertSame(1, CustomerAddress::query()->where('customer_id', $this->customer->getKey())->where('is_default', true)->count());
    }

    public function test_a_customer_cannot_delete_a_foreign_address(): void
    {
        Sanctum::actingAs($this->customer);
        $foreign = CustomerAddress::factory()->create(['customer_id' => Customer::factory()->create()->getKey()]);

        $this->deleteJson("/api/portal/addresses/{$foreign->getKey()}")
            ->assertOk()->assertJsonPath('data.deleted', false);
    }

    /*
    |--------------------------------------------------------------------------
    | Delivery
    |--------------------------------------------------------------------------
    */
    public function test_a_customer_requests_delivery_with_org_priced_fees(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['self' => ['flatFee' => 18]]);
        Sanctum::actingAs($this->customer);

        $response = $this->postJson('/api/portal/delivery', ['type' => 'pickup', 'address' => 'Home street'])
            ->assertCreated();

        $this->assertEquals(18.0, $response->json('data.0.fee'));
        $request = DeliveryRequest::query()->find($response->json('data.0.id'));
        $this->assertSame('portal', $request->source->value);
    }

    public function test_portal_delivery_is_refused_when_ordering_is_disabled(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['portalOrdering' => false]]);
        Sanctum::actingAs($this->customer);

        $this->postJson('/api/portal/delivery', ['type' => 'pickup', 'address' => 'X'])->assertStatus(403);
    }

    public function test_invoice_approval_only_where_required(): void
    {
        Sanctum::actingAs($this->customer);
        $request = DeliveryRequest::factory()->create([
            'organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(), 'invoice_approval_required' => false,
        ]);

        // Nothing to approve.
        $this->postJson("/api/portal/delivery/{$request->getKey()}/approve-invoice")->assertStatus(422);

        $request->update(['invoice_approval_required' => true]);
        $this->postJson("/api/portal/delivery/{$request->getKey()}/approve-invoice")
            ->assertOk()->assertJsonPath('data.invoice_approved_at', fn ($value) => $value !== null);
    }
}

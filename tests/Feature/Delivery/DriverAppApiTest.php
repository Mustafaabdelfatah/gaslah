<?php

namespace Tests\Feature\Delivery;

use App\Enum\Delivery\DeliveryStatusEnum;
use App\Enum\Global\OtpPurposeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\Driver;
use App\Models\Organization;
use App\Models\OtpCode;
use App\Services\Delivery\DeliverySettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DriverAppApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    private Customer $customer;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
        $this->driver = Driver::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'phone' => '0591234567',
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Auth
    |--------------------------------------------------------------------------
    */
    public function test_a_known_driver_logs_in_with_phone_and_otp(): void
    {
        $code = $this->postJson('/api/driver/auth/request-otp', ['phone' => '0591234567'])
            ->assertOk()->json('data.dev_code');
        $this->assertNotNull($code);

        $token = $this->postJson('/api/driver/auth/verify-otp', ['phone' => '0591234567', 'code' => $code])
            ->assertOk()->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/driver/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '0591234567');
    }

    public function test_an_unknown_phone_gets_a_success_shape_without_a_code(): void
    {
        $this->postJson('/api/driver/auth/request-otp', ['phone' => '0500000000'])
            ->assertOk()
            ->assertJsonPath('data.delivered', true)
            ->assertJsonMissingPath('data.dev_code');

        // No code was minted for the unknown number.
        $this->assertSame(0, OtpCode::query()->where('phone', '0500000000')->where('purpose', OtpPurposeEnum::DriverLogin->value)->count());
    }

    public function test_an_inactive_driver_cannot_receive_a_code(): void
    {
        $this->driver->update(['is_active' => false]);

        $this->postJson('/api/driver/auth/request-otp', ['phone' => '0591234567'])
            ->assertOk()
            ->assertJsonMissingPath('data.dev_code');
    }

    /*
    |--------------------------------------------------------------------------
    | Operations
    |--------------------------------------------------------------------------
    */
    public function test_a_driver_sees_only_their_own_requests(): void
    {
        Sanctum::actingAs($this->driver);
        $mine = $this->assignedRequest();
        $other = Driver::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);
        DeliveryRequest::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(), 'customer_id' => $this->customer->getKey(), 'driver_id' => $other->getKey()]);

        $ids = collect($this->getJson('/api/driver/requests')->assertOk()->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->getKey()));
        $this->assertCount(1, $ids);
    }

    public function test_accept_then_advance_respecting_the_acceptance_gate(): void
    {
        Sanctum::actingAs($this->driver);
        $request = $this->assignedRequest();

        // requireAcceptance defaults on, so advancing before accepting is refused.
        $this->postJson("/api/driver/requests/{$request->getKey()}/advance", ['status' => 'picked_up'])
            ->assertStatus(422);

        $this->postJson("/api/driver/requests/{$request->getKey()}/accept")->assertOk();
        $this->postJson("/api/driver/requests/{$request->getKey()}/advance", ['status' => 'picked_up'])
            ->assertOk()->assertJsonPath('data.status', 'picked_up');
    }

    public function test_reject_returns_the_request_to_the_queue(): void
    {
        Sanctum::actingAs($this->driver);
        $request = $this->assignedRequest();

        $this->postJson("/api/driver/requests/{$request->getKey()}/reject", ['reason' => 'busy'])->assertOk();

        $request->refresh();
        $this->assertSame(DeliveryStatusEnum::Requested, $request->status);
        $this->assertNull($request->driver_id);
    }

    public function test_reject_is_refused_after_work_started(): void
    {
        Sanctum::actingAs($this->driver);
        $request = $this->assignedRequest(['accepted_at' => now(), 'status' => DeliveryStatusEnum::PickedUp->value]);

        $this->postJson("/api/driver/requests/{$request->getKey()}/reject")->assertStatus(422);
    }

    public function test_photo_proof_gate_on_delivery_completion(): void
    {
        app(DeliverySettingsService::class)->save($this->organization->getKey(), ['workflow' => ['photoProof' => true]]);
        Sanctum::actingAs($this->driver);
        $request = $this->assignedRequest([
            'type' => 'delivery',
            'accepted_at' => now(),
            'status' => DeliveryStatusEnum::OutForDelivery->value,
        ]);

        $this->postJson("/api/driver/requests/{$request->getKey()}/advance", ['status' => 'delivered'])
            ->assertStatus(422);

        // A valid JPEG proof lets it complete.
        $jpeg = base64_encode("\xFF\xD8\xFF".str_repeat('x', 200));
        $this->postJson("/api/driver/requests/{$request->getKey()}/photo", ['kind' => 'delivery', 'image' => $jpeg])->assertOk();
        $this->postJson("/api/driver/requests/{$request->getKey()}/advance", ['status' => 'delivered'])->assertOk();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function assignedRequest(array $attributes = []): DeliveryRequest
    {
        return DeliveryRequest::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $this->customer->getKey(),
            'driver_id' => $this->driver->getKey(),
            'status' => DeliveryStatusEnum::Assigned->value,
            ...$attributes,
        ]);
    }
}

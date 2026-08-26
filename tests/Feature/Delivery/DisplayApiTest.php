<?php

namespace Tests\Feature\Delivery;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Services\Delivery\DisplayTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class DisplayApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
    }

    public function test_staff_mint_a_display_token_for_their_branch(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $token = $this->postJson('/api/display/token')->assertOk()->json('data.token');

        $this->assertSame($this->branch->getKey(), app(DisplayTokenService::class)->verify($token));
    }

    public function test_staff_cannot_mint_a_token_for_a_foreign_branch(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $foreign = Branch::factory()->create(['organization_id' => $this->createOrganization()->getKey()]);

        $this->postJson('/api/display/token', ['branch_id' => $foreign->getKey()])->assertStatus(403);
    }

    public function test_the_public_board_shows_ready_and_processing_with_first_names_only(): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'name' => 'Sara Ahmed', 'phone' => '0501110000']);
        Order::factory()->create([
            'organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey(),
            'customer_id' => $customer->getKey(), 'status' => OrderStatusEnum::Ready->value, 'order_no' => 'BR-20260826-0007',
        ]);

        $token = app(DisplayTokenService::class)->mint($this->branch->getKey());

        $response = $this->getJson("/api/display/{$token}")->assertOk()->assertJsonPath('data.valid', true);
        $this->assertSame('Sara', $response->json('data.ready.0.first_name'));
        $this->assertSame('0007', $response->json('data.ready.0.short_no'));
        // No phone leaks onto the public board.
        $this->assertStringNotContainsString('0501110000', json_encode($response->json('data')));
    }

    public function test_a_forged_display_token_is_reported_invalid(): void
    {
        $this->getJson('/api/display/999~deadbeef')->assertOk()->assertJsonPath('data.valid', false);
    }

    public function test_a_proof_photo_is_served_only_behind_a_valid_signature(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('delivery-photos/proof.jpg', 'binary');

        $signed = URL::temporarySignedRoute('delivery.photo', now()->addHour(), ['name' => 'proof.jpg']);

        $this->get($signed)->assertOk();
        // Without the signature the URL is rejected.
        $this->get('/api/delivery/photos/proof.jpg')->assertStatus(403);
    }
}

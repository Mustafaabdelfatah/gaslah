<?php

namespace Tests\Feature\Payments;

use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PayoutSettlement;
use App\Models\User;
use App\Services\Payments\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PayoutApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
    }

    /*
    |--------------------------------------------------------------------------
    | Admin — batch creation & maker-checker
    |--------------------------------------------------------------------------
    */
    public function test_creating_a_batch_reserves_the_whole_pool(): void
    {
        $this->seedPool([120, 80]);
        Sanctum::actingAs($this->platformOwner());

        $response = $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending_approval')
            ->assertJsonPath('data.payment_count', 2);

        $this->assertEquals('200.00', $response->json('data.gross_amount'));
        // Every pool payment is now reserved.
        $this->assertSame(0, Payment::query()->where('via_gateway', true)->whereNull('settlement_id')->count());
    }

    public function test_the_creator_cannot_approve_and_approval_needs_distinct_admins(): void
    {
        $this->seedPool([200]);
        $creator = $this->platformOwner();
        $approver1 = $this->platformOwner();
        $approver2 = $this->platformOwner();

        Sanctum::actingAs($creator);
        $id = $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])->json('data.id');

        // The maker may not check their own batch.
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertStatus(403);

        Sanctum::actingAs($approver1);
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'pending_approval');
        // One vote per admin.
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertStatus(409);

        Sanctum::actingAs($approver2);
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');

        // An approved batch can be marked sent.
        $this->postJson("/api/admin/payouts/{$id}/sent", ['transfer_ref' => 'TRX-9'])
            ->assertOk()->assertJsonPath('data.status', 'sent');
    }

    public function test_a_rejection_releases_the_payments(): void
    {
        $this->seedPool([150]);
        Sanctum::actingAs($this->platformOwner());
        $id = $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])->json('data.id');

        Sanctum::actingAs($this->platformOwner());
        $this->postJson("/api/admin/payouts/{$id}/reject", ['reason' => 'wrong bank'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        // The payment is back in the pool.
        $this->assertSame(1, Payment::query()->where('via_gateway', true)->whereNull('settlement_id')->count());
    }

    public function test_updating_the_fee_clears_the_votes(): void
    {
        $this->seedPool([300]);
        $creator = $this->platformOwner();
        Sanctum::actingAs($creator);
        $id = $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])->json('data.id');

        Sanctum::actingAs($this->platformOwner());
        $this->postJson("/api/admin/payouts/{$id}/approve")->assertOk();

        // Changing the fee resets the approvals the voters had cast.
        Sanctum::actingAs($creator);
        $this->patchJson("/api/admin/payouts/{$id}/fee", ['fee' => 30])
            ->assertOk()->assertJsonPath('data.fee_amount', '30.00')->assertJsonPath('data.net_amount', '270.00');

        $this->assertSame(0, PayoutSettlement::query()->find($id)->approvals()->count());
    }

    public function test_only_one_open_batch_per_organization(): void
    {
        $this->seedPool([100]);
        Sanctum::actingAs($this->platformOwner());
        $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])->assertCreated();

        // A second open batch is refused.
        $this->postJson('/api/admin/payouts', ['organization_id' => $this->organization->getKey()])->assertStatus(409);
    }

    public function test_a_non_admin_cannot_use_the_admin_payouts(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/payouts')->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Organization side
    |--------------------------------------------------------------------------
    */
    public function test_an_urgent_request_needs_an_iban(): void
    {
        $this->seedPool([100]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->postJson('/api/payouts/request')->assertStatus(422);

        $this->organization->update(['payout_config' => ['bank' => ['iban' => 'SA'.str_repeat('1', 22)]]]);
        $this->postJson('/api/payouts/request')->assertCreated()->assertJsonPath('data.urgent', true);
    }

    public function test_the_manager_sees_the_unsettled_balance(): void
    {
        $this->seedPool([60, 40]);
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/payouts')
            ->assertOk()
            ->assertJsonPath('data.balance.gross', 100)
            ->assertJsonPath('data.balance.count', 2);
    }

    public function test_the_summary_reports_the_fee_and_whether_a_batch_is_already_open(): void
    {
        app(PayoutService::class)->saveSettings(['fee_fixed' => 5, 'fee_percent' => 10]);
        $this->seedPool([60, 40]);
        $owner = $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        // The tenant should not be deriving either figure from a fee schedule.
        $this->getJson('/api/payouts')
            ->assertOk()
            ->assertJsonPath('data.balance.estimated_fee', 15)
            ->assertJsonPath('data.balance.estimated_net', 85)
            ->assertJsonPath('data.has_open', false);

        app(PayoutService::class)->createBatch($this->organization, $owner->getKey(), null);

        // With a batch in flight the screen can say why, instead of the button
        // discovering it with a 409.
        $this->getJson('/api/payouts')->assertOk()->assertJsonPath('data.has_open', true);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function seedPool(array $amounts): void
    {
        $customer = Customer::factory()->create(['organization_id' => $this->organization->getKey(), 'branch_id' => $this->branch->getKey()]);

        foreach ($amounts as $amount) {
            $order = Order::factory()->create([
                'organization_id' => $this->organization->getKey(),
                'branch_id' => $this->branch->getKey(),
                'customer_id' => $customer->getKey(),
            ]);

            $order->payments()->create([
                'method' => PaymentMethodEnum::Card->value,
                'amount' => $amount,
                'reference' => 'gateway:'.Str::random(16),
                'via_gateway' => true,
            ]);
        }
    }

    private function platformOwner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

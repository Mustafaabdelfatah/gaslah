<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformSubscriptionApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
    }

    /*
    |--------------------------------------------------------------------------
    | Plans
    |--------------------------------------------------------------------------
    */
    public function test_an_admin_creates_a_plan_and_sees_it_listed(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/plans', ['name' => 'Pro', 'monthly_price' => 300, 'yearly_price' => 3000])
            ->assertCreated();

        // Listings follow the starter's paginated envelope (wrapPaginate).
        $this->getJson('/api/admin/plans')->assertOk()->assertJsonPath('data.data.0.name', 'Pro');
    }

    public function test_a_non_admin_cannot_manage_plans(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/plans')->assertStatus(403);
    }

    public function test_the_plan_listing_is_paginated_and_carries_its_commercials(): void
    {
        $plan = PlatformPlan::factory()->create(['name' => 'Pro', 'monthly_price' => 300, 'yearly_price' => 3600]);

        // One monthly at 300 and one yearly at 3600 → 300 + 300 = 600 MRR.
        $this->organization->platformSubscription()->create([
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly',
            'price' => 300, 'started_at' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        [$other] = $this->createTenant();
        $other->platformSubscription()->create([
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'yearly',
            'price' => 3600, 'started_at' => now(), 'current_period_end' => now()->addYear(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/plans')
            ->assertOk()
            ->assertJsonStructure(['data' => ['data', 'current_page', 'per_page', 'total']])
            ->assertJsonPath('data.data.0.subscribers', 2)
            ->assertJsonPath('data.data.0.active_subscribers', 2)
            ->assertJsonPath('data.data.0.mrr', 600);
    }

    public function test_a_partial_plan_update_keeps_the_untouched_fields(): void
    {
        $plan = PlatformPlan::factory()->create(['name' => 'Pro', 'is_active' => true, 'feature_keys' => ['delivery']]);
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/plans/{$plan->getKey()}", ['name' => 'Pro Plus'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Pro Plus')
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.feature_keys', ['delivery']);
    }

    public function test_creating_a_plan_rejects_an_unknown_feature_key(): void
    {
        Sanctum::actingAs($this->owner());

        $this->postJson('/api/admin/plans', [
            'name' => 'Bad', 'monthly_price' => 10, 'yearly_price' => 100,
            'feature_keys' => ['no_such_feature'],
        ])->assertStatus(422)->assertJsonValidationErrors('feature_keys.0');
    }

    /*
    |--------------------------------------------------------------------------
    | Subscription drives entitlements
    |--------------------------------------------------------------------------
    */
    public function test_setting_a_subscription_drives_the_features(): void
    {
        $plan = PlatformPlan::factory()->create(['feature_keys' => ['delivery']]);
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/subscription", [
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly',
        ])->assertOk();

        $entitlements = app(EntitlementService::class);
        $organization = $this->organization->fresh();

        $this->assertTrue($entitlements->isActive($organization));
        // The plan unlocks delivery but not loyalty.
        $this->assertTrue($entitlements->hasFeature($organization, 'delivery'));
        $this->assertFalse($entitlements->hasFeature($organization, 'loyalty'));
    }

    public function test_an_expired_subscription_makes_the_account_read_only(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/subscription", [
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly',
            'current_period_end' => now()->subDay()->toDateString(),
        ])->assertOk();

        $this->assertFalse(app(EntitlementService::class)->isActive($this->organization->fresh()));
    }

    public function test_start_trial_and_extend(): void
    {
        $plan = PlatformPlan::factory()->create();
        Sanctum::actingAs($this->owner());

        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/start-trial", ['plan_id' => $plan->getKey()])
            ->assertOk()
            ->assertJsonPath('data.status', 'trial')
            ->assertJsonPath('data.price', '0.00');

        // Force past due, then extend restores active.
        $this->organization->platformSubscription()->update(['status' => 'past_due']);
        $this->postJson("/api/admin/tenants/{$this->organization->getKey()}/extend", ['days' => 30])
            ->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    /*
    |--------------------------------------------------------------------------
    | Organization view
    |--------------------------------------------------------------------------
    */
    public function test_the_org_reads_its_entitlements(): void
    {
        $plan = PlatformPlan::factory()->create(['name' => 'Pro', 'feature_keys' => ['delivery']]);
        $this->organization->platformSubscription()->create(['plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly', 'started_at' => now(), 'current_period_end' => now()->addMonth()]);

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/org/entitlements')
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.plan_name', 'Pro')
            ->assertJsonPath('data.read_only', false);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

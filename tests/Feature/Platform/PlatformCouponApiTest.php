<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Organization;
use App\Models\PlatformCoupon;
use App\Models\PlatformPlan;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformCouponApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization] = $this->createTenant();
        Sanctum::actingAs($this->owner());
    }

    public function test_percent_and_fixed_and_free_months_effects(): void
    {
        $percent = PlatformCoupon::factory()->make(['type' => 'percent', 'value' => 25]);
        $this->assertSame(75.0, $percent->effect(100)['price']);

        $fixed = PlatformCoupon::factory()->make(['type' => 'fixed', 'value' => 30]);
        $this->assertSame(70.0, $fixed->effect(100)['price']);
        $this->assertSame(0.0, $fixed->effect(20)['price']);

        $free = PlatformCoupon::factory()->make(['type' => 'free_months', 'value' => 2]);
        $effect = $free->effect(100);
        $this->assertSame(100.0, $effect['price']);
        $this->assertSame(2, $effect['extra_months']);
    }

    public function test_an_admin_creates_a_coupon_with_an_uppercased_code(): void
    {
        $this->postJson('/api/admin/coupons', ['code' => 'save20', 'type' => 'percent', 'value' => 20])
            ->assertCreated()
            ->assertJsonPath('data.code', 'SAVE20');
    }

    public function test_a_percent_coupon_discounts_the_subscription_price(): void
    {
        $plan = PlatformPlan::factory()->create(['monthly_price' => 200]);
        PlatformCoupon::factory()->create(['code' => 'HALF', 'type' => 'percent', 'value' => 50]);

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/subscription", [
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'cycle' => 'monthly',
            'coupon_code' => 'half',
        ])
            ->assertOk()
            ->assertJsonPath('data.price', '100.00');

        $this->assertSame(1, PlatformCoupon::query()->firstWhere('code', 'HALF')->redemptions);
    }

    public function test_free_months_extends_the_period(): void
    {
        $plan = PlatformPlan::factory()->create(['monthly_price' => 200]);
        PlatformCoupon::factory()->create(['code' => 'PLUS2', 'type' => 'free_months', 'value' => 2]);

        $end = $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/subscription", [
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'cycle' => 'monthly',
            'coupon_code' => 'PLUS2',
        ])->assertOk()->json('data.current_period_end');

        // One month for the cycle + two free months ≈ 3 months out.
        $this->assertTrue(now()->addMonths(3)->subDays(2)->lessThan($end));
    }

    public function test_an_exhausted_coupon_is_refused_and_not_over_redeemed(): void
    {
        $plan = PlatformPlan::factory()->create();
        PlatformCoupon::factory()->create(['code' => 'ONCE', 'type' => 'percent', 'value' => 10, 'max_redemptions' => 1, 'redemptions' => 1]);

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/subscription", [
            'plan_id' => $plan->getKey(),
            'status' => 'active',
            'cycle' => 'monthly',
            'coupon_code' => 'ONCE',
        ])->assertStatus(422);

        $this->assertSame(1, PlatformCoupon::query()->firstWhere('code', 'ONCE')->redemptions);
    }

    public function test_validate_previews_redeemability(): void
    {
        PlatformCoupon::factory()->create(['code' => 'GOOD', 'is_active' => true]);

        $this->postJson('/api/admin/coupons/validate', ['code' => 'good'])
            ->assertOk()
            ->assertJsonPath('data.found', true)
            ->assertJsonPath('data.redeemable', true);

        $this->postJson('/api/admin/coupons/validate', ['code' => 'nope'])
            ->assertOk()
            ->assertJsonPath('data.found', false);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Enum\Tenancy\PlatformRoleEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\OrgAddon;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OrgAddonApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();

        // A plan that grants nothing beyond the base product, so an add-on is the only
        // way this tenant could hold delivery.
        $this->subscribe(PlatformSubscriptionStatusEnum::Active, featureKeys: []);
    }

    /*
    |--------------------------------------------------------------------------
    | What an add-on actually grants
    |--------------------------------------------------------------------------
    */

    public function test_an_add_on_grants_a_feature_the_plan_does_not(): void
    {
        $entitlements = app(EntitlementService::class);

        $this->assertFalse($entitlements->hasFeature($this->organization, 'delivery'));

        $this->addon('delivery');

        // A tenant whose plan excludes delivery but who bought it separately has it.
        $this->assertTrue($entitlements->hasFeature($this->organization->refresh(), 'delivery'));
    }

    public function test_a_gated_route_opens_once_the_add_on_is_bought(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));

        $this->getJson('/api/delivery/settings')->assertForbidden();

        $this->addon('delivery');

        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->getJson('/api/delivery/settings')->assertOk();
    }

    public function test_a_lapsed_add_on_grants_nothing(): void
    {
        $this->addon('delivery', ['expires_at' => Carbon::now()->subDay()]);

        $this->assertFalse(app(EntitlementService::class)->hasFeature($this->organization->refresh(), 'delivery'));
    }

    public function test_a_switched_off_add_on_grants_nothing(): void
    {
        $this->addon('delivery', ['is_active' => false]);

        $this->assertFalse(app(EntitlementService::class)->hasFeature($this->organization->refresh(), 'delivery'));
    }

    public function test_a_suspended_tenant_loses_the_add_ons_it_stopped_paying_for(): void
    {
        $this->addon('delivery');
        $this->assertTrue(app(EntitlementService::class)->hasFeature($this->organization->refresh(), 'delivery'));

        $this->organization->forceFill(['is_suspended' => true])->save();

        // A suspended account falls back to the base product, add-ons included.
        $this->assertFalse(app(EntitlementService::class)->hasFeature($this->organization->refresh(), 'delivery'));
    }

    public function test_an_operator_override_still_wins_over_an_add_on(): void
    {
        $this->addon('delivery');
        $this->organization->forceFill(['feature_overrides' => ['delivery' => false]])->save();

        // The operator's own switch is the last word, whatever was sold.
        $this->assertFalse(app(EntitlementService::class)->hasFeature($this->organization->refresh(), 'delivery'));
    }

    /*
    |--------------------------------------------------------------------------
    | Selling one
    |--------------------------------------------------------------------------
    */

    public function test_selling_the_same_add_on_twice_tops_up_one_row(): void
    {
        Sanctum::actingAs($this->owner());
        $url = "/api/admin/tenants/{$this->organization->getKey()}/addons";

        $this->putJson($url, ['key' => 'delivery', 'price_monthly' => 50])->assertOk();
        $activatedAt = OrgAddon::query()->firstWhere('key', 'delivery')->activated_at;

        Carbon::setTestNow(Carbon::now()->addDay());
        $this->putJson($url, ['key' => 'delivery', 'price_monthly' => 75])->assertOk();
        Carbon::setTestNow();

        $this->assertSame(1, OrgAddon::query()->where('key', 'delivery')->count());

        $addon = OrgAddon::query()->firstWhere('key', 'delivery');
        $this->assertSame('75.00', $addon->price_monthly);
        // It is when the tenant started paying for it, not when the price last changed.
        $this->assertTrue($activatedAt->equalTo($addon->activated_at));
    }

    public function test_the_response_is_the_resulting_entitlements(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/addons", ['key' => 'delivery'])
            ->assertOk()
            ->assertJsonPath('data.features', fn (array $features): bool => in_array('delivery', $features, true));
    }

    public function test_a_core_feature_cannot_be_sold_as_an_add_on(): void
    {
        Sanctum::actingAs($this->owner());

        // Charging for something the tenant already has as part of the base product.
        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/addons", ['key' => 'orders'])
            ->assertStatus(422);
    }

    public function test_an_unknown_key_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/addons", ['key' => 'nonsense'])
            ->assertStatus(422);
    }

    public function test_an_expiry_in_the_past_is_refused(): void
    {
        Sanctum::actingAs($this->owner());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/addons", [
            'key' => 'delivery', 'expires_at' => Carbon::now()->subDay()->toDateTimeString(),
        ])->assertStatus(422);
    }

    public function test_the_listing_shows_what_is_held_and_what_may_be_sold(): void
    {
        $this->addon('delivery');
        Sanctum::actingAs($this->owner());

        $response = $this->getJson("/api/admin/tenants/{$this->organization->getKey()}/addons")
            ->assertOk()
            ->assertJsonPath('data.addons.0.key', 'delivery')
            ->assertJsonPath('data.addons.0.is_granting', true);

        $sellable = $response->json('data.sellable_keys');
        $this->assertContains('delivery', $sellable);
        // Core features are part of the base product, so they are not on the shelf.
        $this->assertNotContains('orders', $sellable);
    }

    public function test_selling_an_add_on_needs_the_tenants_permission(): void
    {
        Sanctum::actingAs($this->adminWithout());

        $this->putJson("/api/admin/tenants/{$this->organization->getKey()}/addons", ['key' => 'delivery'])
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function addon(string $key, array $attributes = []): OrgAddon
    {
        return OrgAddon::query()->create([
            'organization_id' => $this->organization->getKey(),
            'key' => $key,
            'is_active' => true,
            'activated_at' => Carbon::now(),
            ...$attributes,
        ]);
    }

    /**
     * @param  array<int, string>  $featureKeys
     */
    private function subscribe(PlatformSubscriptionStatusEnum $status, array $featureKeys): void
    {
        $plan = PlatformPlan::factory()->create(['feature_keys' => $featureKeys]);

        PlatformSubscription::query()->create([
            'organization_id' => $this->organization->getKey(),
            'plan_id' => $plan->getKey(),
            'status' => $status->value,
            'started_at' => Carbon::now()->subMonth(),
            'current_period_end' => Carbon::now()->addMonth(),
        ]);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }

    private function adminWithout(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Viewer->value])->save();

        return $user;
    }
}

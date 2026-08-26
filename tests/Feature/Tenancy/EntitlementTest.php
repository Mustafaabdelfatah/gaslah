<?php

namespace Tests\Feature\Tenancy;

use App\Models\Branch;
use App\Services\Tenancy\EntitlementService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EntitlementTest extends TestCase
{
    use RefreshDatabase;

    private EntitlementService $entitlements;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        $this->entitlements = app(EntitlementService::class);
    }

    public function test_an_organization_without_a_subscription_is_fully_entitled(): void
    {
        $organization = $this->createOrganization();

        // Grandfathering: no plan means every feature, unlimited seats, fully active —
        // until an operator intervenes.
        $this->assertTrue($this->entitlements->isActive($organization));
        $this->assertTrue($this->entitlements->hasFeature($organization, 'pos'));
        $this->assertTrue($this->entitlements->hasFeature($organization, 'delivery'));
        $this->assertTrue($this->entitlements->hasFeature($organization, 'analytics'));
        $this->assertSame(EntitlementService::UNLIMITED, $this->entitlements->maxBranches($organization));
    }

    public function test_a_suspended_organization_keeps_only_core_features(): void
    {
        $organization = $this->createOrganization(['is_suspended' => true]);

        $this->assertFalse($this->entitlements->isActive($organization));

        $features = $this->entitlements->features($organization);

        $this->assertContains('pos', $features);
        $this->assertContains('settings', $features);
        $this->assertNotContains('delivery', $features);
        $this->assertNotContains('loyalty', $features);
    }

    public function test_core_features_are_never_withheld_by_an_override(): void
    {
        // An operator switching a core key off must be ignored: the base product
        // cannot be disabled.
        $organization = $this->createOrganization([
            'feature_overrides' => ['pos' => false, 'delivery' => false],
        ]);

        $this->assertTrue($this->entitlements->hasFeature($organization, 'pos'));
        $this->assertFalse($this->entitlements->hasFeature($organization, 'delivery'));
    }

    public function test_requiring_a_disabled_feature_is_forbidden(): void
    {
        $organization = $this->createOrganization([
            'feature_overrides' => ['loyalty' => false],
        ]);

        $this->assertAborts(403, fn () => $this->entitlements->requireFeature($organization, 'loyalty'));
    }

    public function test_writes_on_a_suspended_account_are_refused_with_payment_required(): void
    {
        $organization = $this->createOrganization(['is_suspended' => true]);

        // Payment Required, not Forbidden: the credentials are valid, the plan is not.
        $this->assertAborts(402, fn () => $this->entitlements->requireActive($organization));
    }

    public function test_seat_and_branch_limits_are_enforced_from_the_overrides(): void
    {
        $organization = $this->createOrganization([
            'max_branches_override' => 1,
            'max_users_override' => 1,
        ]);
        Branch::factory()->main()->create(['organization_id' => $organization->getKey()]);

        $this->assertAborts(422, fn () => $this->entitlements->assertBranchQuota($organization));
    }

    public function test_a_disabled_user_does_not_consume_a_seat(): void
    {
        [$organization, $branch] = $this->createTenant();

        $this->createStaff($branch);
        $disabled = $this->createStaff($branch);
        $disabled->update(['is_active' => false]);

        // A business must be able to replace someone who left without first erasing them.
        $this->assertSame(1, $this->entitlements->usedUsers($organization->fresh()));
    }
}

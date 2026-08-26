<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\Organization;
use App\Models\PlatformPlan;
use App\Models\PlatformSubscription;
use App\Models\User;
use App\Services\Platform\DunningService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DunningApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
    }

    public function test_a_disabled_policy_does_nothing(): void
    {
        $this->makeSubscription('active', now()->subDays(20));

        $summary = app(DunningService::class)->run();

        $this->assertSame(0, $summary['processed']);
        $this->assertSame(0, $summary['invoices']);
        $this->assertDatabaseCount('subscription_invoices', 0);
    }

    public function test_a_due_subscription_is_invoiced_and_lapsed_once(): void
    {
        $this->enablePolicy();
        [$organization, $subscription] = $this->makeSubscription('active', now());

        $summary = app(DunningService::class)->run();

        $this->assertSame(1, $summary['invoices']);
        $this->assertSame(1, $summary['lapsed']);
        $this->assertSame('past_due', $subscription->fresh()->status->value);
        $this->assertDatabaseCount('subscription_invoices', 1);

        // A second run is idempotent — no duplicate invoice, no second lapse.
        $second = app(DunningService::class)->run();
        $this->assertSame(0, $second['invoices']);
        $this->assertDatabaseCount('subscription_invoices', 1);
    }

    public function test_a_renewal_that_is_past_grace_suspends_the_account(): void
    {
        $this->enablePolicy();
        [$organization, $subscription] = $this->makeSubscription('active', now()->subDays(20));

        $summary = app(DunningService::class)->run();

        $this->assertSame(1, $summary['suspended']);
        $this->assertTrue($organization->fresh()->is_suspended);
        $this->assertSame('past_due', $subscription->fresh()->status->value);
    }

    public function test_a_pre_renewal_reminder_fires_at_the_threshold(): void
    {
        $this->enablePolicy();
        $this->makeSubscription('active', now()->addDays(3));

        $summary = app(DunningService::class)->run();

        $this->assertSame(1, $summary['reminders']);
        $this->assertSame(0, $summary['invoices']);
        $this->assertDatabaseHas('dunning_logs', ['stage' => 'remind_before']);
    }

    public function test_a_suspended_org_is_skipped(): void
    {
        $this->enablePolicy();
        [$organization] = $this->makeSubscription('past_due', now()->subDays(30));
        $organization->forceFill(['is_suspended' => true])->save();

        $summary = app(DunningService::class)->run();

        $this->assertSame(0, $summary['processed']);
    }

    public function test_the_console_reads_and_saves_the_policy_and_runs(): void
    {
        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/dunning')
            ->assertOk()
            ->assertJsonPath('data.policy.enabled', false);

        $this->putJson('/api/admin/dunning', ['enabled' => true, 'grace_days' => 10])
            ->assertOk()
            ->assertJsonPath('data.enabled', true)
            ->assertJsonPath('data.grace_days', 10);

        $this->postJson('/api/admin/dunning/run')
            ->assertOk()
            ->assertJsonStructure(['data' => ['processed', 'reminders', 'invoices', 'lapsed', 'suspended']]);
    }

    public function test_a_non_admin_cannot_reach_the_dunning_console(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/dunning')->assertStatus(403);
    }

    /**
     * @return array{0: Organization, 1: PlatformSubscription}
     */
    private function makeSubscription(string $status, Carbon $periodEnd): array
    {
        [$organization] = $this->createTenant();
        $plan = PlatformPlan::factory()->create(['monthly_price' => 115]);

        $subscription = $organization->platformSubscription()->create([
            'plan_id' => $plan->getKey(),
            'status' => $status,
            'cycle' => 'monthly',
            'price' => 115,
            'started_at' => now()->subMonth(),
            'current_period_end' => $periodEnd,
        ]);

        return [$organization, $subscription];
    }

    private function enablePolicy(): void
    {
        app(DunningService::class)->savePolicy(['enabled' => true]);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

<?php

namespace Tests\Feature\Platform;

use App\Enum\Tenancy\PlatformRoleEnum;
use App\Models\PlatformPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class PlatformStatsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_report_mrr_arr_and_active_subscription_counts(): void
    {
        [$monthly] = $this->createTenant();
        [$yearly] = $this->createTenant();
        $plan = PlatformPlan::factory()->create();

        // A live monthly sub at 300 and a live yearly sub at 1200 → MRR = 300 + 100 = 400.
        $monthly->platformSubscription()->create([
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly',
            'price' => 300, 'started_at' => now(), 'current_period_end' => now()->addMonth(),
        ]);
        $yearly->platformSubscription()->create([
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'yearly',
            'price' => 1200, 'started_at' => now(), 'current_period_end' => now()->addYear(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_subscriptions', 2)
            ->assertJsonPath('data.kpis.mrr', 400)
            ->assertJsonPath('data.kpis.arr', 4800)
            ->assertJsonPath('data.kpis.tenants', 2)
            ->assertJsonCount(12, 'data.signups_by_month')
            ->assertJsonCount(12, 'data.revenue_by_month');
    }

    public function test_an_expired_subscription_is_excluded_from_mrr(): void
    {
        [$org] = $this->createTenant();
        $plan = PlatformPlan::factory()->create();

        $org->platformSubscription()->create([
            'plan_id' => $plan->getKey(), 'status' => 'active', 'cycle' => 'monthly',
            'price' => 500, 'started_at' => now()->subMonths(2), 'current_period_end' => now()->subDay(),
        ]);

        Sanctum::actingAs($this->owner());

        $this->getJson('/api/admin/stats')
            ->assertOk()
            ->assertJsonPath('data.kpis.active_subscriptions', 0)
            ->assertJsonPath('data.kpis.mrr', 0);
    }

    public function test_finance_permission_is_required(): void
    {
        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/admin/stats')->assertStatus(403);
    }

    private function owner(): User
    {
        $user = $this->createUser();
        $user->forceFill(['is_platform_owner' => true, 'platform_role' => PlatformRoleEnum::Owner->value])->save();

        return $user;
    }
}

<?php

namespace Tests\Feature\Accounting;

use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Budget;
use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    public function test_a_budget_line_is_compared_against_the_expenses_actually_posted(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/accounting/expenses', [
            'date' => '2026-08-15',
            'category' => 'rent',
            'amount' => 3000,
            'paid_from' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/accounting/budgets', [
            'category' => 'rent',
            'month' => '2026-08',
            'amount' => 2500,
        ])->assertCreated();

        $this->getJson('/api/accounting/budgets?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data.data.0.actual', 3000)
            ->assertJsonPath('data.data.0.variance', -500)
            ->assertJsonPath('data.data.0.pct', 120)
            ->assertJsonPath('data.data.0.over_budget', true)
            ->assertJsonPath('data.summary.over_budget', 1);
    }

    public function test_planning_the_same_scope_again_edits_the_line_instead_of_adding_one(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/accounting/budgets', ['category' => 'rent', 'month' => '2026-08', 'amount' => 2500])
            ->assertCreated();
        $this->postJson('/api/accounting/budgets', ['category' => 'rent', 'month' => '2026-08', 'amount' => 4000])
            ->assertCreated();

        $this->assertSame(1, Budget::query()->count());
        $this->assertEquals(4000, Budget::query()->value('amount'));
    }

    public function test_an_expense_outside_the_planned_month_is_not_counted(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/accounting/expenses', [
            'date' => '2026-07-31',
            'category' => 'rent',
            'amount' => 900,
            'paid_from' => 'cash',
        ])->assertCreated();

        $this->postJson('/api/accounting/budgets', ['category' => 'rent', 'month' => '2026-08', 'amount' => 2500])
            ->assertCreated();

        $this->getJson('/api/accounting/budgets?month=2026-08')
            ->assertOk()
            ->assertJsonPath('data.data.0.actual', 0);
    }

    public function test_a_month_must_be_a_real_year_month(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/accounting/budgets', ['category' => 'rent', 'month' => '2026-13', 'amount' => 100])
            ->assertStatus(422);
    }

    public function test_a_budget_of_another_organization_is_not_reachable(): void
    {
        $this->actingAsManager();
        $foreign = Budget::query()->create([
            'organization_id' => $this->createOrganization()->getKey(),
            'category' => 'rent',
            'month' => '2026-08',
            'amount' => 100,
        ]);

        $this->putJson("/api/accounting/budgets/{$foreign->getKey()}", ['amount' => 500])->assertStatus(404);
        $this->deleteJson("/api/accounting/budgets/{$foreign->getKey()}")->assertStatus(404);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function actingAsManager(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }
}

<?php

namespace Tests\Feature\Tenancy;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrganizationApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    /** A posted expense — the same rows the reports below read. */
    private function expense(float $amount, ?int $branchId): Expense
    {
        return app(ExpenseService::class)->record([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $branchId,
            'date' => now()->toDateString(),
            'category' => ExpenseCategoryEnum::cases()[0],
            'amount' => $amount,
        ]);
    }

    public function test_branches_carry_the_headcount_and_traffic_behind_them(): void
    {
        $this->actingAsAdmin();
        $customer = $this->customer($this->branch);
        $this->order($this->branch, 100, 60, $customer);

        $response = $this->getJson('/api/organization/branches')->assertOk();

        $row = $response->json('data.0');
        $this->assertSame(1, $row['orders_count']);
        $this->assertSame(1, $row['customers_count']);
        $this->assertSame(1, $row['employees_count']);
    }

    public function test_a_duplicate_branch_code_is_refused(): void
    {
        $this->actingAsAdmin();

        $this->postJson('/api/organization/branches', ['name' => 'فرع الشمال', 'code' => 'north'])
            ->assertCreated()->assertJsonPath('data.code', 'NORTH');

        $this->postJson('/api/organization/branches', ['name' => 'آخر', 'code' => 'NORTH'])
            ->assertStatus(422);
    }

    public function test_the_last_active_branch_cannot_be_closed(): void
    {
        $this->actingAsAdmin();

        $this->patchJson("/api/organization/branches/{$this->branch->getKey()}", ['is_active' => false])
            ->assertStatus(422);

        // With a second one open, closing the first is fine.
        $second = Branch::factory()->create(['organization_id' => $this->organization->getKey(), 'is_active' => true]);
        $this->patchJson("/api/organization/branches/{$this->branch->getKey()}", ['is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);

        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_a_foreign_branch_cannot_be_amended(): void
    {
        $this->actingAsAdmin();
        [, $otherBranch] = $this->createTenant();

        $this->patchJson("/api/organization/branches/{$otherBranch->getKey()}", ['name' => 'مُخترق'])
            ->assertStatus(404);
    }

    public function test_branch_performance_excludes_cancelled_orders_and_keeps_shared_spend_apart(): void
    {
        $this->actingAsAdmin();
        $customer = $this->customer($this->branch);

        $this->order($this->branch, 200, 150, $customer);
        $this->order($this->branch, 999, 999, $customer, OrderStatusEnum::Cancelled);

        $this->expense(50, $this->branch->getKey());
        // No branch: the organization's shared overhead.
        $this->expense(30, null);

        $response = $this->getJson('/api/organization/performance/branches')->assertOk();

        $response->assertJsonPath('data.branches.0.revenue', 200)
            ->assertJsonPath('data.branches.0.orders_count', 1)
            ->assertJsonPath('data.branches.0.collected', 150)
            ->assertJsonPath('data.branches.0.outstanding', 50)
            ->assertJsonPath('data.branches.0.expenses', 50)
            ->assertJsonPath('data.branches.0.net_contribution', 150)
            // Shared spend is reported apart, never folded into a branch.
            ->assertJsonPath('data.org_shared.expenses', 30)
            ->assertJsonPath('data.totals.expenses', 50);
    }

    public function test_employee_performance_prorates_a_declared_salary_over_the_period(): void
    {
        $admin = $this->actingAsAdmin();
        $customer = $this->customer($this->branch);
        $this->order($this->branch, 300, 300, $customer, cashier: $admin);

        $this->putJson("/api/organization/employees/{$admin->getKey()}/cost", ['monthly_salary' => 3000])
            ->assertOk()->assertJsonPath('data.monthly_salary', '3000.00');

        // A 30-day window is exactly one month's worth of that salary.
        $to = now()->toDateString();
        $from = now()->subDays(29)->toDateString();
        $response = $this->getJson("/api/organization/performance/employees?from={$from}&to={$to}")->assertOk();

        $row = collect($response->json('data.employees'))->firstWhere('user_id', $admin->getKey());
        $this->assertSame(300, $row['sales_total']);
        $this->assertSame(3000, $row['period_cost']);
        $this->assertSame(0.1, $row['revenue_per_cost_ratio']);

        // Clearing it takes the figure away rather than zeroing it.
        $this->deleteJson("/api/organization/employees/{$admin->getKey()}/cost")->assertOk();
        $response = $this->getJson("/api/organization/performance/employees?from={$from}&to={$to}")->assertOk();
        $row = collect($response->json('data.employees'))->firstWhere('user_id', $admin->getKey());
        $this->assertNull($row['monthly_cost']);
        $this->assertNull($row['revenue_per_cost_ratio']);
    }

    public function test_costs_report_payroll_at_organization_level_only(): void
    {
        $admin = $this->actingAsAdmin();
        $this->putJson("/api/organization/employees/{$admin->getKey()}/cost", ['monthly_salary' => 3000])->assertOk();

        $this->expense(40, $this->branch->getKey());

        $response = $this->getJson('/api/organization/costs')->assertOk();

        // A person may work in more than one branch, so their salary is never split
        // across branches — it sits once at organization level.
        $response->assertJsonPath('data.by_branch.0.payroll_declared', 0)
            ->assertJsonPath('data.by_branch.0.expenses_total', 40)
            ->assertJsonPath('data.totals.expenses_total', 40);

        $this->assertGreaterThan(0, $response->json('data.org_shared.payroll_declared'));
        $this->assertNotEmpty($response->json('data.payroll_note'));
    }

    public function test_a_branch_manager_may_read_but_not_declare_a_salary(): void
    {
        $manager = $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::BranchManager));

        $this->getJson('/api/organization/performance/branches')->assertOk();

        $this->putJson("/api/organization/employees/{$manager->getKey()}/cost", ['monthly_salary' => 1])
            ->assertStatus(403);
        $this->postJson('/api/organization/branches', ['name' => 'فرع جديد'])->assertStatus(403);
    }

    public function test_a_cashier_cannot_read_the_organization_at_all(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/organization/branches')->assertStatus(403);
    }

    private function actingAsAdmin(): User
    {
        return $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    private function customer(Branch $branch): Customer
    {
        return Customer::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $branch->getKey(),
        ]);
    }

    private function order(
        Branch $branch,
        float $total,
        float $paid,
        Customer $customer,
        OrderStatusEnum $status = OrderStatusEnum::Received,
        ?User $cashier = null,
    ): Order {
        return Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $branch->getKey(),
            'customer_id' => $customer->getKey(),
            'cashier_id' => $cashier?->getKey(),
            'grand_total' => $total,
            'paid_total' => $paid,
            'status' => $status->value,
        ]);
    }
}

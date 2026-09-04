<?php

namespace App\Services\Tenancy;

use App\Enum\Orders\OrderStatusEnum;
use App\Models\Branch;
use App\Models\EmployeeCost;
use App\Models\Expense;
use App\Models\Order;
use App\Models\Shift;
use App\Models\User;
use App\Support\BusinessDateRange;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organization seen as a business rather than a till: what each branch and each
 * person brought in over a period, and what they cost.
 *
 * Everything here reads; the only writes are the branch record itself and the declared
 * salaries, neither of which touches the ledger. Cancelled orders are excluded
 * throughout — a cancelled basket is not revenue anybody earned.
 *
 * Expenses with no branch are the organization's shared overhead. They are reported in
 * their own bucket rather than spread across branches or silently dropped, because any
 * split would be an assumption this service is not entitled to make.
 */
class OrganizationService
{
    /**
     * Normalise what ReportRangeService resolved into the window this service reads.
     *
     * That service reports `days` as the list of day keys and the count as
     * `period_days`; everything here only ever needs the count.
     *
     * @param  array<string, mixed>  $resolved
     * @return array<string, mixed>
     */
    public function window(array $resolved): array
    {
        return [
            'from' => $resolved['from_local']->format('Y-m-d'),
            'to' => $resolved['to_inclusive_local']->format('Y-m-d'),
            'from_local' => $resolved['from_local'],
            'from_utc' => $resolved['from_utc'],
            'to_exclusive_utc' => $resolved['to_exclusive_utc'],
            'days' => (int) $resolved['period_days'],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Branches
    |--------------------------------------------------------------------------
    */

    /**
     * Every branch with the headcount and the traffic behind it.
     *
     * @return Collection<int, Branch>
     */
    public function branches(int $organizationId): Collection
    {
        return Branch::query()
            ->where('organization_id', $organizationId)
            ->withCount(['userBranches as employees_count', 'orders as orders_count', 'customers as customers_count'])
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createBranch(int $organizationId, array $data): Branch
    {
        $this->assertCodeIsFree($organizationId, $data['code'] ?? null, null);

        return Branch::query()->create([
            ...$data,
            'organization_id' => $organizationId,
            'is_active' => $data['is_active'] ?? true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateBranch(Branch $branch, array $data): Branch
    {
        $this->assertCodeIsFree($branch->organization_id, $data['code'] ?? null, $branch->getKey());

        // Closing the last open branch would leave the organization with nowhere to
        // take an order, so it is refused rather than left to fail at the till.
        if (array_key_exists('is_active', $data) && ! $data['is_active'] && $branch->is_active) {
            $othersActive = Branch::query()
                ->where('organization_id', $branch->organization_id)
                ->whereKeyNot($branch->getKey())
                ->where('is_active', true)
                ->exists();

            if (! $othersActive) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.branch_last_active'));
            }
        }

        $branch->update($data);

        return $branch->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Performance
    |--------------------------------------------------------------------------
    */

    /**
     * What each branch brought in and spent over the period, against the same-length
     * window immediately before it.
     *
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function branchPerformance(int $organizationId, array $range): array
    {
        $branches = $this->branches($organizationId);

        $orders = $this->orderTotalsByBranch($organizationId, $range);
        $expenses = $this->expenseTotalsByBranch($organizationId, $range);
        $shifts = $this->shiftTotalsByBranch($organizationId, $range);

        $rows = $branches->map(function (Branch $branch) use ($orders, $expenses, $shifts) {
            $id = $branch->getKey();
            $order = $orders[$id] ?? ['revenue' => 0.0, 'orders_count' => 0, 'collected' => 0.0];
            $spend = round((float) ($expenses[$id] ?? 0), 2);
            $revenue = $order['revenue'];

            return [
                'branch_id' => $id,
                'name' => $branch->name,
                'code' => $branch->code,
                'is_active' => (bool) $branch->is_active,

                'revenue' => $revenue,
                'orders_count' => $order['orders_count'],
                'aov' => $order['orders_count'] > 0 ? round($revenue / $order['orders_count'], 2) : 0.0,
                'collected' => $order['collected'],
                'outstanding' => round(max(0, $revenue - $order['collected']), 2),

                'expenses' => $spend,
                'net_contribution' => round($revenue - $spend, 2),

                'employees_count' => (int) $branch->employees_count,
                'shifts_count' => (int) ($shifts[$id]['shifts_count'] ?? 0),
                'cash_variance' => round((float) ($shifts[$id]['cash_variance'] ?? 0), 2),
            ];
        })->values();

        $prior = $this->priorRange($range);

        return [
            'range' => ['from' => $range['from'], 'to' => $range['to']],
            'branches' => $rows->all(),
            'org_shared' => ['expenses' => $this->orgSharedExpenses($organizationId, $range)],
            'totals' => [
                'revenue' => round($rows->sum('revenue'), 2),
                'orders_count' => (int) $rows->sum('orders_count'),
                'collected' => round($rows->sum('collected'), 2),
                'outstanding' => round($rows->sum('outstanding'), 2),
                'expenses' => round($rows->sum('expenses'), 2),
                'net_contribution' => round($rows->sum('net_contribution'), 2),
                'cash_variance' => round($rows->sum('cash_variance'), 2),
            ],
            'previous_period' => [
                'revenue' => round(array_sum(array_column($this->orderTotalsByBranch($organizationId, $prior), 'revenue')), 2),
                'expenses' => round(
                    array_sum($this->expenseTotalsByBranch($organizationId, $prior))
                    + $this->orgSharedExpenses($organizationId, $prior),
                    2,
                ),
            ],
        ];
    }

    /**
     * What each person sold, collected and cost over the period.
     *
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function employeePerformance(int $organizationId, array $range, ?int $branchId): array
    {
        $staff = User::query()
            ->whereHas('userBranches.branch', fn ($q) => $q->where('organization_id', $organizationId))
            ->with('userBranches.branch:id,name')
            ->orderBy('name')
            ->get();

        $orders = $this->orderTotalsByCashier($organizationId, $range, $branchId);
        $shifts = $this->shiftTotalsByUser($organizationId, $range, $branchId);
        $expenses = $this->expenseCountsByAuthor($organizationId, $range, $branchId);
        $salaries = EmployeeCost::query()
            ->where('organization_id', $organizationId)
            ->pluck('monthly_salary', 'user_id');

        // A month is the unit a salary is declared in, so the period's share of it is
        // that salary prorated over the days actually being measured.
        $days = max(1, $range['days']);
        $monthShare = $days / 30;

        $rows = $staff
            ->when($branchId !== null, fn ($c) => $c->filter(
                fn (User $user) => $user->userBranches->contains('branch_id', $branchId),
            ))
            ->map(function (User $user) use ($orders, $shifts, $expenses, $salaries, $monthShare) {
                $id = $user->getKey();
                $order = $orders[$id] ?? ['orders_count' => 0, 'sales_total' => 0.0, 'collected_total' => 0.0];
                $monthly = isset($salaries[$id]) ? round((float) $salaries[$id], 2) : null;
                $periodCost = $monthly === null ? null : round($monthly * $monthShare, 2);

                return [
                    'user_id' => $id,
                    'name' => $user->name,
                    'role' => $user->role,
                    'branches' => $user->userBranches->map(fn ($m) => $m->branch?->name)->filter()->values(),

                    'orders_count' => $order['orders_count'],
                    'sales_total' => $order['sales_total'],
                    'collected_total' => $order['collected_total'],

                    'shifts_count' => (int) ($shifts[$id]['shifts_count'] ?? 0),
                    'shift_hours' => round((float) ($shifts[$id]['shift_hours'] ?? 0), 2),
                    'cash_variance' => round((float) ($shifts[$id]['cash_variance'] ?? 0), 2),
                    'expenses_created' => (int) ($expenses[$id] ?? 0),

                    'monthly_cost' => $monthly,
                    'period_cost' => $periodCost,
                    'revenue_per_cost_ratio' => $periodCost > 0
                        ? round($order['sales_total'] / $periodCost, 2)
                        : null,
                ];
            })
            ->values();

        return [
            'range' => ['from' => $range['from'], 'to' => $range['to'], 'days' => $days],
            'employees' => $rows->all(),
            'totals' => [
                'sales_total' => round($rows->sum('sales_total'), 2),
                'collected_total' => round($rows->sum('collected_total'), 2),
                'orders_count' => (int) $rows->sum('orders_count'),
                'period_cost' => round($rows->sum('period_cost'), 2),
                'cash_variance' => round($rows->sum('cash_variance'), 2),
            ],
        ];
    }

    /**
     * What the organization spent over the period, by branch and by category.
     *
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    public function costs(int $organizationId, array $range): array
    {
        $branches = $this->branches($organizationId);
        $byCategory = $this->expensesByBranchAndCategory($organizationId, $range);

        // Payroll is attributed to a person, not a branch, so it belongs to whichever
        // branches they work in — and a person in two branches would be counted twice.
        // It is reported once, at the organization level, and said so plainly.
        $payroll = round(
            (float) EmployeeCost::query()
                ->where('organization_id', $organizationId)
                ->sum('monthly_salary') * (max(1, $range['days']) / 30),
            2,
        );

        $rows = $branches->map(function (Branch $branch) use ($byCategory) {
            $categories = $byCategory[$branch->getKey()] ?? [];
            $total = round(array_sum($categories), 2);

            return [
                'branch_id' => $branch->getKey(),
                'name' => $branch->name,
                'code' => $branch->code,
                'expenses_by_category' => $categories,
                'expenses_total' => $total,
                'payroll_declared' => 0.0,
                'total_cost' => $total,
            ];
        })->values();

        $sharedCategories = $byCategory[0] ?? [];
        $sharedTotal = round(array_sum($sharedCategories), 2);
        $expensesTotal = round($rows->sum('expenses_total') + $sharedTotal, 2);

        return [
            'range' => ['from' => $range['from'], 'to' => $range['to'], 'days' => max(1, $range['days'])],
            'by_branch' => $rows->all(),
            'org_shared' => [
                'expenses_by_category' => $sharedCategories,
                'expenses_total' => $sharedTotal,
                'payroll_declared' => $payroll,
                'total_cost' => round($sharedTotal + $payroll, 2),
            ],
            'payroll_note' => __('api.organization_payroll_note'),
            'totals' => [
                'expenses_total' => $expensesTotal,
                'payroll_declared' => $payroll,
                'total_cost' => round($expensesTotal + $payroll, 2),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Declared salaries
    |--------------------------------------------------------------------------
    */

    public function setEmployeeCost(int $organizationId, User $user, float $monthlySalary, ?string $note): EmployeeCost
    {
        return EmployeeCost::query()->updateOrCreate(
            ['organization_id' => $organizationId, 'user_id' => $user->getKey()],
            ['monthly_salary' => round($monthlySalary, 2), 'note' => $note],
        );
    }

    public function clearEmployeeCost(int $organizationId, User $user): void
    {
        EmployeeCost::query()
            ->where('organization_id', $organizationId)
            ->where('user_id', $user->getKey())
            ->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function assertCodeIsFree(int $organizationId, ?string $code, ?int $exceptId): void
    {
        if ($code === null || $code === '') {
            return;
        }

        $taken = Branch::query()
            ->where('organization_id', $organizationId)
            ->where('code', mb_strtoupper($code))
            ->when($exceptId !== null, fn ($q) => $q->whereKeyNot($exceptId))
            ->exists();

        if ($taken) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.branch_code_taken'));
        }
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<int, array{revenue: float, orders_count: int, collected: float}>
     */
    private function orderTotalsByBranch(int $organizationId, array $range): array
    {
        return $this->liveOrders($organizationId, $range)
            ->selectRaw('branch_id, COUNT(*) as orders_count, SUM(grand_total) as revenue, SUM(paid_total) as collected')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id')
            ->map(fn ($row) => [
                'revenue' => round((float) $row->revenue, 2),
                'orders_count' => (int) $row->orders_count,
                'collected' => round((float) $row->collected, 2),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<int, array{orders_count: int, sales_total: float, collected_total: float}>
     */
    private function orderTotalsByCashier(int $organizationId, array $range, ?int $branchId): array
    {
        return $this->liveOrders($organizationId, $range)
            ->whereNotNull('cashier_id')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('cashier_id, COUNT(*) as orders_count, SUM(grand_total) as sales_total, SUM(paid_total) as collected_total')
            ->groupBy('cashier_id')
            ->get()
            ->keyBy('cashier_id')
            ->map(fn ($row) => [
                'orders_count' => (int) $row->orders_count,
                'sales_total' => round((float) $row->sales_total, 2),
                'collected_total' => round((float) $row->collected_total, 2),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<int, float>
     */
    private function expenseTotalsByBranch(int $organizationId, array $range): array
    {
        return $this->periodExpenses($organizationId, $range)
            ->whereNotNull('branch_id')
            ->selectRaw('branch_id, SUM(amount) as total')
            ->groupBy('branch_id')
            ->pluck('total', 'branch_id')
            ->map(fn ($total) => round((float) $total, 2))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function orgSharedExpenses(int $organizationId, array $range): float
    {
        return round((float) $this->periodExpenses($organizationId, $range)->whereNull('branch_id')->sum('amount'), 2);
    }

    /**
     * Spend per branch per category. Organization-level expenses are keyed under 0,
     * which is not a branch id any database will ever issue.
     *
     * @param  array<string, mixed>  $range
     * @return array<int, array<string, float>>
     */
    private function expensesByBranchAndCategory(int $organizationId, array $range): array
    {
        $grouped = [];
        $rows = $this->periodExpenses($organizationId, $range)
            ->selectRaw('branch_id, category, COALESCE(SUM(amount), 0) as total')
            ->groupBy('branch_id', 'category')
            ->get();

        foreach ($rows as $row) {
            $key = $row->branch_id ?? 0;
            $category = is_object($row->category) ? $row->category->value : ($row->category ?? 'other');
            $grouped[$key][$category] = round((float) $row->total, 2);
        }

        return $grouped;
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<int, array{shifts_count: int, cash_variance: float}>
     */
    private function shiftTotalsByBranch(int $organizationId, array $range): array
    {
        return $this->periodShifts($organizationId, $range)
            ->selectRaw('branch_id, COUNT(*) as shifts_count, SUM(variance) as cash_variance')
            ->groupBy('branch_id')
            ->get()
            ->keyBy('branch_id')
            ->map(fn ($row) => [
                'shifts_count' => (int) $row->shifts_count,
                'cash_variance' => round((float) $row->cash_variance, 2),
            ])
            ->all();
    }

    /**
     * Shift totals per person. The duration expression is dialect-specific, but the
     * aggregation remains in SQL so a long report never hydrates every historical shift.
     *
     * @param  array<string, mixed>  $range
     * @return array<int, array{shifts_count: int, shift_hours: float, cash_variance: float}>
     */
    private function shiftTotalsByUser(int $organizationId, array $range, ?int $branchId): array
    {
        $duration = match (DB::connection()->getDriverName()) {
            'sqlite' => '(julianday(closed_at) - julianday(opened_at)) * 24',
            'pgsql' => 'EXTRACT(EPOCH FROM (closed_at - opened_at)) / 3600',
            'sqlsrv' => 'DATEDIFF(second, opened_at, closed_at) / 3600.0',
            default => 'TIMESTAMPDIFF(SECOND, opened_at, closed_at) / 3600',
        };

        return $this->periodShifts($organizationId, $range)
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw("user_id, COUNT(*) as shifts_count, COALESCE(SUM(variance), 0) as cash_variance, COALESCE(SUM(CASE WHEN closed_at IS NULL THEN 0 ELSE {$duration} END), 0) as shift_hours")
            ->groupBy('user_id')
            ->get()
            ->keyBy('user_id')
            ->map(fn ($row) => [
                'shifts_count' => (int) $row->shifts_count,
                'shift_hours' => round((float) $row->shift_hours, 2),
                'cash_variance' => round((float) $row->cash_variance, 2),
            ])
            ->all();
    }

    /**
     * @param  array<string, mixed>  $range
     * @return array<int, int>
     */
    private function expenseCountsByAuthor(int $organizationId, array $range, ?int $branchId): array
    {
        return $this->periodExpenses($organizationId, $range)
            ->whereNotNull('created_by_id')
            ->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('created_by_id, COUNT(*) as total')
            ->groupBy('created_by_id')
            ->pluck('total', 'created_by_id')
            ->map(fn ($total) => (int) $total)
            ->all();
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function liveOrders(int $organizationId, array $range): Builder
    {
        return Order::query()
            ->where('organization_id', $organizationId)
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', $range['from_utc'])
            ->where('created_at', '<', $range['to_exclusive_utc']);
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function periodExpenses(int $organizationId, array $range): Builder
    {
        return Expense::query()
            ->where('organization_id', $organizationId)
            // Inclusive-exclusive bounds work for MySQL DATE and SQLite's timestamp
            // representation while keeping the organization/date index sargable.
            ->where('date', '>=', $range['from'])
            ->where('date', '<', BusinessDateRange::dayAfter($range['to']));
    }

    /**
     * @param  array<string, mixed>  $range
     */
    private function periodShifts(int $organizationId, array $range): Builder
    {
        return Shift::query()
            ->where('organization_id', $organizationId)
            ->where('opened_at', '>=', $range['from_utc'])
            ->where('opened_at', '<', $range['to_exclusive_utc']);
    }

    /**
     * The same-length window immediately before the one given.
     *
     * @param  array<string, mixed>  $range
     * @return array<string, mixed>
     */
    private function priorRange(array $range): array
    {
        $days = max(1, $range['days']);

        $fromLocal = $range['from_local']->subDays($days);
        $toLocal = $range['from_local']->subDay();

        return [
            'from' => $fromLocal->format('Y-m-d'),
            'to' => $toLocal->format('Y-m-d'),
            'from_local' => $fromLocal,
            'from_utc' => $fromLocal->utc(),
            'to_exclusive_utc' => $range['from_local']->utc(),
            'days' => $days,
        ];
    }
}

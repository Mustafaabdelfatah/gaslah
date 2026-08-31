<?php

namespace App\Services\Accounting;

use App\Models\Budget;
use App\Models\Expense;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Planned spend versus what was actually posted.
 *
 * Nothing here touches the ledger: a budget is a yardstick, and the expenses it
 * is measured against are read back exactly as they were posted.
 */
class BudgetService
{
    /**
     * The month's budget lines, each carrying the actual spend it is measured against.
     *
     * A line with no branch plans the whole organization. That line is only
     * meaningful while the caller can see every branch — scoped to one branch it
     * would be compared against a fraction of its own spend and read as a large
     * underspend, so it is dropped from the answer instead.
     *
     * @param  array<int, int>  $branchIds  the branches in the caller's read scope
     * @return array<string, mixed>
     */
    public function forMonth(int $organizationId, string $month, array $branchIds, bool $branchScoped): array
    {
        $actuals = $this->actualsByBranchAndCategory($organizationId, $month, $branchIds);

        $lines = Budget::query()
            ->where('organization_id', $organizationId)
            ->forMonth($month)
            ->when($branchScoped, fn ($q) => $q->whereIn('branch_id', $branchIds))
            ->with('branch:id,name')
            ->orderBy('branch_id')
            ->orderBy('category')
            ->get()
            ->map(function (Budget $budget) use ($actuals) {
                $amount = (float) $budget->amount;
                $actual = $budget->branch_id === null
                    ? $this->categoryTotal($actuals, $budget->category->value)
                    : ($actuals["{$budget->branch_id}:{$budget->category->value}"] ?? 0.0);

                return [
                    'id' => $budget->id,
                    'branch_id' => $budget->branch_id,
                    'branch' => $budget->branch?->name,
                    'category' => $budget->category->value,
                    'month' => $budget->month,
                    'amount' => round($amount, 2),
                    'note' => $budget->note,
                    'actual' => round($actual, 2),
                    'variance' => round($amount - $actual, 2),
                    'pct' => $amount > 0 ? round(($actual / $amount) * 100, 1) : null,
                    'over_budget' => $actual > $amount,
                ];
            });

        return [
            'month' => $month,
            'data' => $lines->values()->all(),
            'summary' => [
                'total_budget' => round($lines->sum('amount'), 2),
                // Each posted expense counts once even when both an organization-wide
                // line and a branch line plan the same category.
                'total_actual' => $this->totalActual($actuals, $lines),
                'over_budget' => $lines->where('over_budget', true)->count(),
                'line_count' => $lines->count(),
            ],
        ];
    }

    /**
     * Upsert a line — planning the same branch, category and month again edits it.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsert(int $organizationId, array $data, ?int $userId = null): Budget
    {
        return Budget::query()->updateOrCreate(
            [
                'organization_id' => $organizationId,
                'branch_id' => $data['branch_id'] ?? null,
                'category' => $data['category'],
                'month' => $data['month'],
            ],
            [
                'amount' => round((float) $data['amount'], 2),
                'note' => $data['note'] ?? null,
                'created_by_id' => $userId,
            ],
        )->refresh();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Posted spend for the month, keyed "branchId:category".
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<string, float>
     */
    private function actualsByBranchAndCategory(int $organizationId, string $month, array $branchIds): Collection
    {
        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfMonth();

        return Expense::query()
            ->where('organization_id', $organizationId)
            // An expense with no branch belongs to the organization, so it counts
            // wherever the caller can see — a branch filter must not hide it.
            ->when($branchIds !== [], fn ($q) => $q->where(
                fn ($sub) => $sub->whereIn('branch_id', $branchIds)->orWhereNull('branch_id'),
            ))
            // whereDate, not whereBetween: SQLite keeps a date column as a full
            // timestamp string, so the last day of the month sorts after the bare
            // date bound and its spend silently drops out of the month it belongs to.
            ->whereDate('date', '>=', $start->toDateString())
            ->whereDate('date', '<=', $start->copy()->endOfMonth()->toDateString())
            ->get(['branch_id', 'category', 'amount'])
            ->groupBy(fn (Expense $expense) => "{$expense->branch_id}:{$expense->category->value}")
            ->map(fn (Collection $rows) => round((float) $rows->sum('amount'), 2));
    }

    /**
     * Spend on one category across every branch in scope.
     *
     * @param  Collection<string, float>  $actuals
     */
    private function categoryTotal(Collection $actuals, string $category): float
    {
        return round(
            $actuals->filter(fn (float $v, string $key) => str_ends_with($key, ":{$category}"))->sum(),
            2,
        );
    }

    /**
     * Total actual spend across the budgeted categories, counting each expense once.
     *
     * @param  Collection<string, float>  $actuals
     * @param  Collection<int, array<string, mixed>>  $lines
     */
    private function totalActual(Collection $actuals, Collection $lines): float
    {
        $budgetedCategories = $lines->pluck('category')->unique();

        return round(
            $actuals
                ->filter(fn (float $v, string $key) => $budgetedCategories->contains(explode(':', $key, 2)[1] ?? ''))
                ->sum(),
            2,
        );
    }
}

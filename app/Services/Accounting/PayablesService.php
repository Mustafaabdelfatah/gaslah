<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\PayableSettlementMethodEnum;
use App\Enum\Accounting\PayableStatusEnum;
use App\Enum\Accounting\RecurringFrequencyEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Accounts Payable engine: accrual, aging, settlement, voiding and recurrence.
 *
 * A bill reuses ExpenseService to post Dr expense + Dr input VAT / Cr AP. Paying it
 * posts Dr AP / Cr cash or bank; voiding mirrors the accrual before deleting the bill.
 */
class PayablesService
{
    private const MAX_CATCH_UP_RUNS = 60;

    public function __construct(
        private readonly ExpenseService $expenses,
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Bills in the caller's branch scope plus the AP headline and aging totals.
     *
     * @param  array<int, int>  $branchIds
     * @return array{bills: EloquentCollection<int, Payable>, summary: array<string, mixed>}
     */
    public function overview(int $organizationId, array $branchIds): array
    {
        $bills = Payable::query()
            ->forOrganization($organizationId)
            ->whereHas('expense', fn ($query) => $query->where(
                fn ($scope) => $scope->whereNull('branch_id')->orWhereIn('branch_id', $branchIds),
            ))
            ->with(['expense', 'supplier:id,name'])
            ->orderBy('due_date')
            ->get();

        $today = Carbon::today();
        $monthStart = $today->copy()->startOfMonth();
        $aging = ['current' => 0.0, 'd1_30' => 0.0, 'd31_60' => 0.0, 'd61_90' => 0.0, 'd90p' => 0.0];
        $totalOpen = $overdue = $dueSoon = $paidThisMonth = 0.0;

        foreach ($bills as $bill) {
            $amount = round((float) $bill->expense->amount, 2);

            if ($bill->status === PayableStatusEnum::Paid) {
                if ($bill->paid_at?->greaterThanOrEqualTo($monthStart)) {
                    $paidThisMonth += $amount;
                }

                continue;
            }

            $totalOpen += $amount;
            $due = Carbon::parse($bill->due_date)->startOfDay();

            if ($due->lt($today)) {
                $overdue += $amount;
            }
            if ($due->betweenIncluded($today, $today->copy()->addDays(7))) {
                $dueSoon += $amount;
            }

            $days = $due->lt($today) ? (int) $due->diffInDays($today) : 0;
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => 'd1_30',
                $days <= 60 => 'd31_60',
                $days <= 90 => 'd61_90',
                default => 'd90p',
            };
            $aging[$bucket] += $amount;
        }

        return [
            'bills' => $bills,
            'summary' => [
                'total_open' => round($totalOpen, 2),
                'overdue' => round($overdue, 2),
                'due_soon' => round($dueSoon, 2),
                'paid_this_month' => round($paidThisMonth, 2),
                'open_count' => $bills->where('status', PayableStatusEnum::Open)->count(),
                'aging' => array_map(static fn (float $amount) => round($amount, 2), $aging),
            ],
        ];
    }

    /**
     * Accrue a supplier bill and its AP journal entry atomically.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBill(
        int $organizationId,
        ?int $branchId,
        ?int $userId,
        array $data,
        ?int $recurringBillId = null,
        bool $systemDated = false,
    ): Payable {
        $issueDate = Carbon::parse($data['issue_date'] ?? Carbon::today())->toDateString();

        return DB::transaction(function () use ($organizationId, $branchId, $userId, $data, $recurringBillId, $systemDated, $issueDate) {
            $expense = $this->expenses->record([
                'organization_id' => $organizationId,
                'branch_id' => $branchId,
                'date' => $issueDate,
                'category' => $data['category'],
                'amount' => $data['amount'],
                'vat_amount' => $data['vat_amount'] ?? 0,
                'paid_from' => ExpensePaidFromEnum::AccountsPayable,
                'description' => $data['description'] ?? null,
                'reference' => $data['bill_no'] ?? null,
                'created_by_id' => $userId,
                'system_dated' => $systemDated,
            ]);

            return Payable::query()->create([
                'organization_id' => $organizationId,
                'expense_id' => $expense->getKey(),
                'supplier_id' => $data['supplier_id'] ?? null,
                'bill_no' => $data['bill_no'] ?? null,
                'issue_date' => $issueDate,
                'due_date' => Carbon::parse($data['due_date'])->toDateString(),
                'status' => PayableStatusEnum::Open->value,
                'recurring_bill_id' => $recurringBillId,
            ])->load(['expense', 'supplier:id,name']);
        });
    }

    /**
     * Settle one open bill: Dr AP / Cr cash or bank.
     */
    public function settle(
        Payable $payable,
        PayableSettlementMethodEnum|string $via,
        ?string $date,
        ?int $userId,
    ): Payable {
        $via = $via instanceof PayableSettlementMethodEnum
            ? $via
            : PayableSettlementMethodEnum::from($via);

        return DB::transaction(function () use ($payable, $via, $date, $userId) {
            /** @var Payable $locked */
            $locked = Payable::query()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PayableStatusEnum::Paid) {
                abort(422, __('api.payable_already_paid'));
            }

            $locked->loadMissing('expense');
            $amount = round((float) $locked->expense->amount, 2);
            $when = $date === null ? Carbon::now() : Carbon::parse($date);
            $organizationId = $locked->organization_id;

            $entry = $this->posting->post([
                'organization_id' => $organizationId,
                'source' => JournalSourceEnum::Payment,
                'ref_type' => 'PayableSettlement',
                'ref_id' => $locked->expense_id,
                'date' => $when,
                'memo' => __('api.payable_settlement_memo', ['id' => $locked->getKey()]),
                'branch_id' => $locked->expense->branch_id,
                'created_by_id' => $userId,
                'lines' => [
                    ['account_id' => $this->account($organizationId, SystemAccountEnum::AccountsPayable), 'debit' => $amount],
                    ['account_id' => $this->account($organizationId, $via->systemAccount()), 'credit' => $amount],
                ],
            ]);

            $locked->forceFill([
                'status' => PayableStatusEnum::Paid->value,
                'paid_at' => $when,
                'paid_via' => $via->value,
                'paid_journal_entry_id' => $entry->getKey(),
            ])->save();

            return $locked->refresh()->load(['expense', 'supplier:id,name']);
        });
    }

    /**
     * Void an open bill. Its accrual is mirrored; no ledger row is erased.
     */
    public function void(Payable $payable, ?int $userId): void
    {
        DB::transaction(function () use ($payable, $userId) {
            /** @var Payable $locked */
            $locked = Payable::query()->whereKey($payable->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PayableStatusEnum::Paid) {
                abort(422, __('api.payable_paid_cannot_be_voided'));
            }

            $locked->loadMissing('expense');
            $expense = $locked->expense;

            // Delete explicitly so the payable's own activity event is retained; the
            // subsequent expense deletion would otherwise remove it through the FK.
            $locked->delete();
            $this->expenses->reverseAndDelete($expense, $userId);
        });
    }

    /**
     * Suppliers with the open balance visible in the caller's branch scope.
     *
     * @param  array<int, int>  $branchIds
     * @return Collection<int, array<string, mixed>>
     */
    public function suppliers(int $organizationId, array $branchIds): Collection
    {
        $balances = Payable::query()
            ->where('payables.organization_id', $organizationId)
            ->where('payables.status', PayableStatusEnum::Open->value)
            ->whereNotNull('payables.supplier_id')
            ->join('expenses', 'expenses.id', '=', 'payables.expense_id')
            ->where(
                fn ($scope) => $scope->whereNull('expenses.branch_id')->orWhereIn('expenses.branch_id', $branchIds),
            )
            ->selectRaw('payables.supplier_id, SUM(expenses.amount) as total')
            ->groupBy('payables.supplier_id')
            ->get()
            ->pluck('total', 'supplier_id')
            ->map(fn ($total) => round((float) $total, 2));

        return Supplier::query()
            ->forOrganization($organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(fn (Supplier $supplier) => [
                'id' => $supplier->id,
                'name' => $supplier->name,
                'phone' => $supplier->phone,
                'open_balance' => $balances->get($supplier->getKey(), 0.0),
            ]);
    }

    /**
     * Recurring templates visible in the caller's branch scope.
     *
     * @param  array<int, int>  $branchIds
     * @return EloquentCollection<int, RecurringBill>
     */
    public function recurring(int $organizationId, array $branchIds): EloquentCollection
    {
        return RecurringBill::query()
            ->forOrganization($organizationId)
            ->where(fn ($query) => $query->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
            ->with(['supplier:id,name', 'branch:id,name'])
            ->orderBy('next_run')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createRecurring(int $organizationId, ?int $defaultBranchId, array $data): RecurringBill
    {
        return RecurringBill::query()->create([
            'organization_id' => $organizationId,
            'name' => $data['name'],
            'category' => $data['category'],
            'amount' => round((float) $data['amount'], 2),
            'vat_amount' => round((float) ($data['vat_amount'] ?? 0), 2),
            'supplier_id' => $data['supplier_id'] ?? null,
            'branch_id' => $data['branch_id'] ?? $defaultBranchId,
            'paid_from' => $data['paid_from'],
            'frequency' => $data['frequency'],
            'anchor_day' => (int) ($data['anchor_day'] ?? 1),
            'due_days' => (int) ($data['due_days'] ?? 0),
            'next_run' => Carbon::parse($data['start_date'] ?? Carbon::today())->toDateString(),
            'is_active' => $data['is_active'] ?? true,
            'description' => $data['description'] ?? null,
        ])->load(['supplier:id,name', 'branch:id,name']);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateRecurring(RecurringBill $recurring, array $data): RecurringBill
    {
        $recurring->fill([
            'name' => $data['name'],
            'category' => $data['category'],
            'amount' => round((float) $data['amount'], 2),
            'vat_amount' => round((float) ($data['vat_amount'] ?? 0), 2),
            'supplier_id' => $data['supplier_id'] ?? null,
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : $recurring->branch_id,
            'paid_from' => $data['paid_from'],
            'frequency' => $data['frequency'],
            'anchor_day' => (int) ($data['anchor_day'] ?? 1),
            'due_days' => (int) ($data['due_days'] ?? 0),
            'is_active' => $data['is_active'] ?? $recurring->is_active,
            'description' => $data['description'] ?? null,
        ]);

        if (! empty($data['start_date'])) {
            $recurring->next_run = Carbon::parse($data['start_date'])->toDateString();
        }

        $recurring->save();

        return $recurring->refresh()->load(['supplier:id,name', 'branch:id,name']);
    }

    /**
     * Generate one scheduled occurrence and advance from that occurrence, never now.
     *
     * @return array{type: string, id: int}
     */
    public function materialize(RecurringBill $recurring, ?int $userId = null, ?Carbon $occurrence = null): array
    {
        return DB::transaction(function () use ($recurring, $userId, $occurrence) {
            /** @var RecurringBill $locked */
            $locked = RecurringBill::query()->whereKey($recurring->getKey())->lockForUpdate()->firstOrFail();
            $when = $occurrence?->copy() ?? Carbon::parse($locked->next_run);

            if ($locked->paid_from === ExpensePaidFromEnum::AccountsPayable) {
                $bill = $this->createBill(
                    $locked->organization_id,
                    $locked->branch_id,
                    $userId,
                    [
                        'supplier_id' => $locked->supplier_id,
                        'amount' => $locked->amount,
                        'vat_amount' => $locked->vat_amount,
                        'category' => $locked->category,
                        'issue_date' => $when->toDateString(),
                        'due_date' => $when->copy()->addDays($locked->due_days)->toDateString(),
                        'description' => $locked->description ?: $locked->name,
                    ],
                    recurringBillId: $locked->getKey(),
                    systemDated: true,
                );
                $result = ['type' => 'bill', 'id' => $bill->getKey()];
            } else {
                $expense = $this->expenses->record([
                    'organization_id' => $locked->organization_id,
                    'branch_id' => $locked->branch_id,
                    'date' => $when->toDateString(),
                    'category' => $locked->category,
                    'amount' => $locked->amount,
                    'vat_amount' => $locked->vat_amount,
                    'paid_from' => $locked->paid_from,
                    'description' => $locked->description ?: $locked->name,
                    'created_by_id' => $userId,
                    'system_dated' => true,
                ]);
                $result = ['type' => 'expense', 'id' => $expense->getKey()];
            }

            $locked->forceFill([
                'last_run' => $when->toDateString(),
                'next_run' => $this->nextRun($when, $locked->frequency, $locked->anchor_day)->toDateString(),
                'generated_count' => $locked->generated_count + 1,
            ])->save();

            return $result;
        });
    }

    /**
     * Catch every active template up to today, capped per template for safety.
     */
    public function runDue(int $organizationId, ?int $userId = null): int
    {
        $today = Carbon::today();
        $generated = 0;

        $templates = RecurringBill::query()
            ->forOrganization($organizationId)
            ->where('is_active', true)
            ->where('next_run', '<', $today->copy()->addDay()->toDateString())
            ->get();

        foreach ($templates as $template) {
            for ($guard = 0; $guard < self::MAX_CATCH_UP_RUNS; $guard++) {
                $template->refresh();

                if (! $template->is_active || $template->next_run->gt($today)) {
                    break;
                }

                $this->materialize($template, $userId, Carbon::parse($template->next_run));
                $generated++;
            }
        }

        return $generated;
    }

    public function nextRun(
        Carbon $from,
        RecurringFrequencyEnum|string $frequency,
        int $anchorDay,
    ): Carbon {
        $frequency = $frequency instanceof RecurringFrequencyEnum
            ? $frequency
            : RecurringFrequencyEnum::from($frequency);

        return match ($frequency) {
            RecurringFrequencyEnum::Weekly => $from->copy()->addWeek(),
            RecurringFrequencyEnum::Yearly => $from->copy()->addYear(),
            RecurringFrequencyEnum::Monthly => $this->nextMonthlyRun($from, $anchorDay),
        };
    }

    private function nextMonthlyRun(Carbon $from, int $anchorDay): Carbon
    {
        $next = $from->copy()->addMonthNoOverflow()->startOfMonth();

        return $next->day(min(max($anchorDay, 1), $next->daysInMonth));
    }

    private function account(int $organizationId, SystemAccountEnum $account): int
    {
        return $this->chart->systemAccount($organizationId, $account)->getKey();
    }
}

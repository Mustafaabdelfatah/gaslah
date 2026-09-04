<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\AccountTypeEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\Order;
use App\Support\BusinessDateRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only financial reports derived entirely from journal lines.
 *
 * Every figure is aggregated in SQL rather than in PHP, and every report is scoped to
 * one organization. Balances are always computed here, never read from a stored column.
 */
class AccountingReportService
{
    /**
     * Signed balance per account.
     *
     * @param  array{from?: string|null, to?: string|null, branch_id?: int|null}  $filter
     * @return Collection<int, array{account: Account, debit: float, credit: float, balance: float}>
     */
    public function balancesByAccount(int $organizationId, array $filter = [], bool $cumulative = false, bool $includeZero = false): Collection
    {
        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.organization_id', $organizationId)
            ->when(! $cumulative && ! empty($filter['from']), fn ($q) => $q->where('journal_entries.date', '>=', $filter['from']))
            ->when(! empty($filter['to']), fn ($q) => $q->where('journal_entries.date', '<', BusinessDateRange::dayAfter($filter['to'])))
            ->when(! empty($filter['branch_id']), fn ($q) => $q->where('journal_lines.branch_id', $filter['branch_id']))
            ->groupBy('journal_lines.account_id')
            ->selectRaw('journal_lines.account_id, SUM(journal_lines.debit) AS debit, SUM(journal_lines.credit) AS credit')
            ->get()
            ->keyBy('account_id');

        $accounts = Account::query()->forOrganization($organizationId)->orderBy('code')->get();

        return $accounts->map(function (Account $account) use ($rows) {
            $debit = round((float) ($rows[$account->getKey()]->debit ?? 0), 2);
            $credit = round((float) ($rows[$account->getKey()]->credit ?? 0), 2);

            return [
                'account' => $account,
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $account->type->balance($debit, $credit),
            ];
        })->filter(fn (array $row) => $includeZero || $row['debit'] !== 0.0 || $row['credit'] !== 0.0)
            ->values();
    }

    /**
     * Trial balance — every account with its debit and credit totals; it must sum to zero.
     *
     * @return array{rows: array<int, array<string, mixed>>, total_debit: float, total_credit: float, balanced: bool}
     */
    public function trialBalance(int $organizationId, array $filter = []): array
    {
        $balances = $this->balancesByAccount($organizationId, $filter);

        $totalDebit = round($balances->sum('debit'), 2);
        $totalCredit = round($balances->sum('credit'), 2);

        return [
            'rows' => $balances->map(fn (array $row) => [
                'account' => $this->presentAccount($row['account']),
                'debit' => $row['debit'],
                'credit' => $row['credit'],
            ])->all(),
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'balanced' => $this->equalHalalas($totalDebit, $totalCredit),
        ];
    }

    /**
     * Income statement for the period.
     *
     * @return array{revenue: array<int, array<string, mixed>>, expenses: array<int, array<string, mixed>>, total_revenue: float, total_expenses: float, net_income: float}
     */
    public function incomeStatement(int $organizationId, array $filter = []): array
    {
        return $this->incomeFromBalances($this->balancesByAccount($organizationId, $filter));
    }

    /**
     * The accounting landing figures in one call.
     *
     * Positions (cash, bank, receivables, VAT owed) are cumulative to the end of
     * the period, because what the tenant holds is a running total; revenue and
     * expenses are the period's own movement. Mixing the two in one payload is
     * what the screen actually shows, so it is settled here rather than left to
     * the caller to assemble from three reports.
     *
     * `bank` is null when the tenant never opened a bank account, which lets the
     * screen fold it into the cash tile instead of showing a hollow zero.
     *
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    public function overview(int $organizationId, array $filter = []): array
    {
        $cumulative = $this->balancesByAccount(
            $organizationId,
            ['to' => $filter['to'] ?? null, 'branch_id' => $filter['branch_id'] ?? null],
            cumulative: true,
            includeZero: true,
        )->keyBy(fn (array $row) => $row['account']->system_key);

        // With no lower bound, the cumulative and period balances are identical.
        // Reuse the rows instead of issuing the same aggregate and account query twice.
        $income = empty($filter['from'])
            ? $this->incomeFromBalances($cumulative->values())
            : $this->incomeStatement($organizationId, $filter);

        // "No bank" means nothing was ever booked to it, not that the account is
        // missing — the default chart always creates one.
        $bankRow = $cumulative->get(SystemAccountEnum::Bank->value);
        $hasBank = $bankRow !== null && ($bankRow['debit'] !== 0.0 || $bankRow['credit'] !== 0.0);

        return [
            'cash' => $this->netMovement($cumulative, SystemAccountEnum::Cash, credit: false),
            'bank' => $hasBank ? $this->netMovement($cumulative, SystemAccountEnum::Bank, credit: false) : null,
            'receivable' => $this->netMovement($cumulative, SystemAccountEnum::AccountsReceivable, credit: false),
            'payable' => $this->netMovement($cumulative, SystemAccountEnum::AccountsPayable, credit: true),
            'vat_payable' => round(
                $this->netMovement($cumulative, SystemAccountEnum::VatPayable, credit: true)
                - $this->netMovement($cumulative, SystemAccountEnum::InputVat, credit: false),
                2,
            ),
            'revenue' => $income['total_revenue'],
            'expenses' => $income['total_expenses'],
            'net_income' => $income['net_income'],
        ];
    }

    /**
     * Who owes the tenant money, aged.
     *
     * The ledger's receivables account gives one number; collecting needs the
     * names behind it, so this is built from the orders themselves — every
     * order still carrying a balance, grouped by customer and bucketed by how
     * long the oldest of their unpaid orders has been outstanding.
     *
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function receivables(int $organizationId, array $branchIds = []): array
    {
        $timezone = (string) config('project.project.timezone', 'Asia/Riyadh');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $currentCutoff = $today->subDays(30)->utc();
        $day30Cutoff = $today->subDays(60)->utc();
        $day60Cutoff = $today->subDays(90)->utc();

        $rows = Order::query()
            ->where('orders.organization_id', $organizationId)
            ->when($branchIds !== [], fn ($q) => $q->whereIn('orders.branch_id', $branchIds))
            ->outstanding()
            ->leftJoin('customers', 'customers.id', '=', 'orders.customer_id')
            ->selectRaw(
                'orders.customer_id, customers.name, customers.phone,
                 COUNT(*) as orders_count,
                 MIN(orders.created_at) as oldest_at,
                 COALESCE(SUM(orders.grand_total - orders.paid_total), 0) as due,
                 COALESCE(SUM(CASE WHEN orders.created_at >= ? THEN orders.grand_total - orders.paid_total ELSE 0 END), 0) as current_due,
                 COALESCE(SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN orders.grand_total - orders.paid_total ELSE 0 END), 0) as d30_due,
                 COALESCE(SUM(CASE WHEN orders.created_at >= ? AND orders.created_at < ? THEN orders.grand_total - orders.paid_total ELSE 0 END), 0) as d60_due,
                 COALESCE(SUM(CASE WHEN orders.created_at < ? THEN orders.grand_total - orders.paid_total ELSE 0 END), 0) as d90_due',
                [
                    $currentCutoff,
                    $day30Cutoff, $currentCutoff,
                    $day60Cutoff, $day30Cutoff,
                    $day60Cutoff,
                ],
            )
            ->groupBy('orders.customer_id', 'customers.name', 'customers.phone')
            ->orderByDesc('due')
            ->get();

        $buckets = [
            'current' => round((float) $rows->sum('current_due'), 2),
            'd30' => round((float) $rows->sum('d30_due'), 2),
            'd60' => round((float) $rows->sum('d60_due'), 2),
            'd90' => round((float) $rows->sum('d90_due'), 2),
        ];

        $customers = $rows->map(function ($row) use ($today, $timezone): array {
            $oldestDays = (int) CarbonImmutable::parse($row->oldest_at)
                ->setTimezone($timezone)
                ->startOfDay()
                ->diffInDays($today);

            return [
                'customer' => [
                    'id' => $row->customer_id === null ? null : (int) $row->customer_id,
                    'name' => $row->name,
                    'phone' => $row->phone,
                ],
                'orders_count' => (int) $row->orders_count,
                'due' => round((float) $row->due, 2),
                'oldest_days' => $oldestDays,
                'bucket' => $this->agingBucket($oldestDays),
            ];
        })->all();

        return [
            'as_of' => $today->toDateString(),
            'total' => round((float) $rows->sum('due'), 2),
            'buckets' => $buckets,
            'customers' => $customers,
        ];
    }

    /**
     * Which aging bucket a debt that is $days old belongs to.
     */
    private function agingBucket(int $days): string
    {
        return match (true) {
            $days <= 30 => 'current',
            $days <= 60 => 'd30',
            $days <= 90 => 'd60',
            default => 'd90',
        };
    }

    /**
     * Balance sheet as of a date. Equity carries the period's net income.
     *
     * @return array<string, mixed>
     */
    public function balanceSheet(int $organizationId, ?string $asOf = null, ?int $branchId = null): array
    {
        $filter = ['to' => $asOf ?? CarbonImmutable::now()->toDateString(), 'branch_id' => $branchId];
        $balances = $this->balancesByAccount($organizationId, $filter, cumulative: true);

        $assets = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Asset);
        $liabilities = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Liability);
        $equity = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Equity);
        $revenue = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Revenue);
        $expenses = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Expense);

        $totalAssets = round($assets->sum('balance'), 2);
        $totalLiabilities = round($liabilities->sum('balance'), 2);
        $equityBase = round($equity->sum('balance'), 2);
        $netIncome = round($revenue->sum('balance') - $expenses->sum('balance'), 2);
        $totalEquity = round($equityBase + $netIncome, 2);

        return [
            'assets' => $this->presentBalances($assets),
            'liabilities' => $this->presentBalances($liabilities),
            'equity' => $this->presentBalances($equity),
            'total_assets' => $totalAssets,
            'total_liabilities' => $totalLiabilities,
            'equity_base' => $equityBase,
            'net_income' => $netIncome,
            'total_equity' => $totalEquity,
            'balanced' => $this->equalHalalas($totalAssets, round($totalLiabilities + $totalEquity, 2)),
        ];
    }

    /**
     * Account ledger with a running balance, including the opening balance before the
     * period so the running figure is absolute.
     *
     * @return array<string, mixed>
     */
    public function ledger(Account $account, ?string $from = null, ?string $to = null): array
    {
        $opening = $this->openingBalance($account, $from);

        $rows = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.account_id', $account->getKey())
            ->when($from, fn ($q) => $q->where('journal_entries.date', '>=', $from))
            ->when($to, fn ($q) => $q->where('journal_entries.date', '<', BusinessDateRange::dayAfter($to)))
            ->orderBy('journal_entries.date')
            ->orderBy('journal_entries.entry_no')
            ->select('journal_entries.entry_no', 'journal_entries.date', 'journal_entries.memo', 'journal_entries.source', 'journal_lines.debit', 'journal_lines.credit')
            ->get();

        $running = $opening;
        $isDebitNormal = $account->type->isDebitNormal();

        $lines = $rows->map(function ($row) use (&$running, $isDebitNormal) {
            $delta = $isDebitNormal
                ? (float) $row->debit - (float) $row->credit
                : (float) $row->credit - (float) $row->debit;
            $running = round($running + $delta, 2);

            return [
                'entry_no' => $row->entry_no,
                'date' => $row->date,
                'memo' => $row->memo,
                'source' => $row->source,
                'debit' => round((float) $row->debit, 2),
                'credit' => round((float) $row->credit, 2),
                'balance' => $running,
            ];
        });

        return [
            'account' => $this->presentAccount($account),
            'opening_balance' => $opening,
            'closing_balance' => $running,
            'rows' => $lines->all(),
        ];
    }

    /**
     * VAT return for the period (output VAT collected minus recoverable input VAT).
     *
     * @return array<string, mixed>
     */
    public function vatReturn(int $organizationId, array $filter = []): array
    {
        $balances = $this->balancesByAccount($organizationId, $filter, includeZero: true)->keyBy(fn (array $row) => $row['account']->system_key);

        $outputVat = $this->netMovement($balances, SystemAccountEnum::VatPayable, credit: true);
        $inputVat = $this->netMovement($balances, SystemAccountEnum::InputVat, credit: false);
        $netVat = round($outputVat - $inputVat, 2);

        return [
            'output_vat' => $outputVat,
            'input_vat' => $inputVat,
            'standard_rated_sales' => $outputVat > 0 ? round($outputVat / 0.15, 2) : 0.0,
            'standard_rated_purchases' => $inputVat > 0 ? round($inputVat / 0.15, 2) : 0.0,
            'net_vat' => $netVat,
            'net_vat_due' => max(0.0, $netVat),
            'net_vat_refundable' => max(0.0, -$netVat),
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function openingBalance(Account $account, ?string $from): float
    {
        if ($from === null) {
            return 0.0;
        }

        $row = DB::table('journal_lines')
            ->join('journal_entries', 'journal_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_lines.account_id', $account->getKey())
            ->where('journal_entries.date', '<', $from)
            ->selectRaw('COALESCE(SUM(journal_lines.debit),0) AS debit, COALESCE(SUM(journal_lines.credit),0) AS credit')
            ->first();

        return $account->type->balance((float) $row->debit, (float) $row->credit);
    }

    /**
     * @param  Collection<int, array{system_key: string|null}|array<string, mixed>>  $balances
     */
    private function netMovement(Collection $balances, SystemAccountEnum $key, bool $credit): float
    {
        $row = $balances->get($key->value);

        if ($row === null) {
            return 0.0;
        }

        return $credit
            ? round($row['credit'] - $row['debit'], 2)
            : round($row['debit'] - $row['credit'], 2);
    }

    /**
     * @param  Collection<int, array{account: Account, debit: float, credit: float, balance: float}>  $balances
     * @return array<string, mixed>
     */
    private function incomeFromBalances(Collection $balances): array
    {
        $revenue = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Revenue);
        $expenses = $balances->filter(fn (array $row) => $row['account']->type === AccountTypeEnum::Expense);
        $totalRevenue = round($revenue->sum('balance'), 2);
        $totalExpenses = round($expenses->sum('balance'), 2);

        return [
            'revenue' => $this->presentBalances($revenue),
            'expenses' => $this->presentBalances($expenses),
            'total_revenue' => $totalRevenue,
            'total_expenses' => $totalExpenses,
            'net_income' => round($totalRevenue - $totalExpenses, 2),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $balances
     * @return array<int, array<string, mixed>>
     */
    private function presentBalances(Collection $balances): array
    {
        return $balances->map(fn (array $row) => [
            'account' => $this->presentAccount($row['account']),
            'balance' => $row['balance'],
        ])->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentAccount(Account $account): array
    {
        return [
            'id' => $account->id,
            'code' => $account->code,
            'name' => $account->name,
            'type' => $account->type->value,
            'system_key' => $account->system_key,
        ];
    }

    private function equalHalalas(float $a, float $b): bool
    {
        return (int) round($a * 100) === (int) round($b * 100);
    }
}

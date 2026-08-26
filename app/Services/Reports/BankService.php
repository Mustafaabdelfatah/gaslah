<?php

namespace App\Services\Reports;

use App\Enum\Accounting\SystemAccountEnum;
use App\Models\BankReconciliation;
use App\Models\JournalLine;
use App\Services\Accounting\ChartOfAccountsService;

/**
 * Organization-level bank reconciliation.
 *
 * Every journal line that touched the BANK account is a book line; the accountant marks
 * lines cleared and enters the statement balance. The reconciliation shows the cleared
 * balance against the statement and the pending items. The book balance is the full
 * cumulative history (a windowed balance would be wrong); the visible list is capped.
 */
class BankService
{
    private const MAX_LIMIT = 2000;

    private const DEFAULT_LIMIT = 500;

    public function __construct(private readonly ChartOfAccountsService $chart) {}

    /**
     * @return array<string, mixed>
     */
    public function reconciliation(int $organizationId, int $limit): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $bankAccountId = $this->bankAccountId($organizationId);
        $state = $this->state($organizationId);
        $clearedIds = collect($state->cleared_line_ids ?? [])->map(fn ($id) => (int) $id)->all();

        $bookBalance = round((float) JournalLine::query()
            ->where('account_id', $bankAccountId)
            ->selectRaw('COALESCE(SUM(debit - credit),0) as balance')
            ->value('balance'), 2);

        $clearedBalance = $clearedIds === [] ? 0.0 : round((float) JournalLine::query()
            ->where('account_id', $bankAccountId)
            ->whereIn('id', $clearedIds)
            ->selectRaw('COALESCE(SUM(debit - credit),0) as balance')
            ->value('balance'), 2);

        $clearedCount = $clearedIds === [] ? 0 : JournalLine::query()
            ->where('account_id', $bankAccountId)
            ->whereIn('id', $clearedIds)
            ->count();

        $total = JournalLine::query()->where('account_id', $bankAccountId)->count();

        $lines = JournalLine::query()
            ->where('account_id', $bankAccountId)
            ->with('entry:id,date,memo,source')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        $clearedSet = array_flip($clearedIds);
        $unclearedIn = 0.0;
        $unclearedOut = 0.0;

        $rows = $lines->map(function (JournalLine $line) use ($clearedSet, &$unclearedIn, &$unclearedOut) {
            $cleared = isset($clearedSet[$line->getKey()]);
            $in = round((float) $line->debit, 2);
            $out = round((float) $line->credit, 2);

            if (! $cleared) {
                $unclearedIn += $in;
                $unclearedOut += $out;
            }

            return [
                'id' => $line->getKey(),
                'date' => $line->entry?->date,
                'memo' => $line->entry?->memo,
                'amount_in' => $in,
                'amount_out' => $out,
                'cleared' => $cleared,
            ];
        });

        $statementBalance = round((float) $state->statement_balance, 2);
        $difference = round($statementBalance - $clearedBalance, 2);

        return [
            'summary' => [
                'book_balance' => $bookBalance,
                'cleared_balance' => $clearedBalance,
                'uncleared_in' => round($unclearedIn, 2),
                'uncleared_out' => round($unclearedOut, 2),
                'statement_balance' => $statementBalance,
                'difference' => $difference,
                'reconciled' => abs($difference) < 0.01,
                'cleared_count' => $clearedCount,
                'line_count' => $rows->count(),
                'truncated' => $total > $limit,
            ],
            'lines' => $rows,
        ];
    }

    /**
     * Toggle whether a single line is cleared.
     *
     * @return array<string, mixed>
     */
    public function toggleClear(int $organizationId, int $lineId, bool $cleared): array
    {
        $state = $this->state($organizationId);
        $ids = collect($state->cleared_line_ids ?? [])->map(fn ($id) => (int) $id);

        $ids = $cleared ? $ids->push($lineId)->unique()->values() : $ids->reject(fn ($id) => $id === $lineId)->values();

        $this->persist($organizationId, ['cleared_line_ids' => $ids->all()]);

        return $this->reconciliation($organizationId, self::DEFAULT_LIMIT);
    }

    /**
     * Mark every bank line cleared, or clear the whole set.
     *
     * @return array<string, mixed>
     */
    public function clearAll(int $organizationId, bool $cleared): array
    {
        $ids = $cleared
            ? JournalLine::query()->where('account_id', $this->bankAccountId($organizationId))->pluck('id')->all()
            : [];

        $this->persist($organizationId, ['cleared_line_ids' => $ids]);

        return $this->reconciliation($organizationId, self::DEFAULT_LIMIT);
    }

    /**
     * Set the actual statement balance (recorded in the audit trail).
     *
     * @return array<string, mixed>
     */
    public function setStatementBalance(int $organizationId, float $balance): array
    {
        $this->persist($organizationId, ['statement_balance' => round($balance, 2)]);

        activity()
            ->withProperties(['organization_id' => $organizationId, 'balance' => round($balance, 2)])
            ->log('bank_statement_balance_set');

        return $this->reconciliation($organizationId, self::DEFAULT_LIMIT);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function state(int $organizationId): BankReconciliation
    {
        return BankReconciliation::query()->firstOrNew(['organization_id' => $organizationId]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function persist(int $organizationId, array $attributes): void
    {
        BankReconciliation::query()->updateOrCreate(['organization_id' => $organizationId], $attributes);
    }

    private function bankAccountId(int $organizationId): int
    {
        return $this->chart->systemAccount($organizationId, SystemAccountEnum::Bank)->getKey();
    }
}

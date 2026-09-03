<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\PayableStatusEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Expense;
use Illuminate\Support\Facades\DB;

/**
 * Records expenses and posts their double-entry.
 *
 * Net (amount minus recoverable VAT) is debited to the category account, the VAT to
 * Input VAT, and the gross credited to whatever funded it. Deletion never erases the
 * ledger: a reversing entry is posted first, then the row is removed.
 */
class ExpenseService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
        private readonly BooksLockService $booksLock,
    ) {}

    /**
     * @param  array{
     *     organization_id: int,
     *     date: string,
     *     category: ExpenseCategoryEnum|string,
     *     amount: float|int,
     *     vat_amount?: float|int,
     *     paid_from?: ExpensePaidFromEnum|string,
     *     description?: string|null,
     *     reference?: string|null,
     *     branch_id?: int|null,
     *     created_by_id?: int|null,
     *     system_dated?: bool,
     * }  $data
     */
    public function record(array $data): Expense
    {
        $organizationId = $data['organization_id'];
        $category = $this->category($data['category']);
        $paidFrom = $this->paidFrom($data['paid_from'] ?? ExpensePaidFromEnum::Cash);

        // A user-chosen date is locked. A recurring schedule dates its own occurrence,
        // so blocking a missed occurrence would make the nightly catch-up fail forever.
        if (! ($data['system_dated'] ?? false)) {
            $this->booksLock->assertOpen($organizationId, $data['date']);
        }

        $amount = round((float) $data['amount'], 2);
        $vat = round((float) ($data['vat_amount'] ?? 0), 2);
        $net = round($amount - $vat, 2);

        return DB::transaction(function () use ($organizationId, $category, $paidFrom, $amount, $vat, $net, $data) {
            $categoryAccount = $this->chart->systemAccount($organizationId, $category->systemAccount());
            $inputVat = $this->chart->systemAccount($organizationId, SystemAccountEnum::InputVat);
            $fundingAccount = $this->chart->systemAccount($organizationId, $paidFrom->systemAccount());

            $expense = Expense::query()->create([
                'organization_id' => $organizationId,
                'branch_id' => $data['branch_id'] ?? null,
                'date' => $data['date'],
                'category' => $category->value,
                'description' => $data['description'] ?? null,
                'amount' => $amount,
                'vat_amount' => $vat,
                'account_id' => $categoryAccount->getKey(),
                'paid_from' => $paidFrom->value,
                'reference' => $data['reference'] ?? null,
                'created_by_id' => $data['created_by_id'] ?? null,
            ]);

            $entry = $this->posting->post([
                'organization_id' => $organizationId,
                'source' => JournalSourceEnum::Expense,
                'ref_type' => 'Expense',
                'ref_id' => $expense->getKey(),
                'date' => $data['date'],
                'memo' => __('api.expense_memo', ['category' => $category->value, 'description' => $data['description'] ?? '']),
                'branch_id' => $data['branch_id'] ?? null,
                'created_by_id' => $data['created_by_id'] ?? null,
                'lines' => [
                    ['account_id' => $categoryAccount->getKey(), 'debit' => $net],
                    ['account_id' => $inputVat->getKey(), 'debit' => $vat],
                    ['account_id' => $fundingAccount->getKey(), 'credit' => $amount],
                ],
            ]);

            $expense->forceFill(['journal_entry_id' => $entry->getKey()])->save();

            return $expense->refresh();
        });
    }

    /**
     * Reverse an expense's ledger effect, then remove the row.
     *
     * The original journal entry is never deleted; a mirror entry zeroes its effect so
     * the audit trail stays intact.
     */
    public function reverseAndDelete(Expense $expense, ?int $createdById = null): void
    {
        DB::transaction(function () use ($expense, $createdById) {
            $expense->loadMissing(['journalEntry', 'payable']);

            // The generic expenses endpoint must not bypass the AP workflow: after a
            // settlement, deleting the accrual would leave the ledger with an orphaned
            // Dr AP / Cr cash entry and corrupt the supplier balance.
            if ($expense->payable?->status === PayableStatusEnum::Paid) {
                abort(422, __('api.payable_paid_cannot_be_voided'));
            }

            if ($expense->journalEntry !== null) {
                $this->posting->post([
                    'organization_id' => $expense->organization_id,
                    'source' => JournalSourceEnum::Expense,
                    'ref_type' => 'ExpenseReversal',
                    'ref_id' => $expense->getKey(),
                    'date' => $expense->date,
                    'memo' => __('api.expense_reversal_memo'),
                    'branch_id' => $expense->branch_id,
                    'created_by_id' => $createdById,
                    'lines' => $expense->journalEntry->lines->map(fn ($line) => [
                        'account_id' => $line->account_id,
                        'debit' => (float) $line->credit,
                        'credit' => (float) $line->debit,
                        'branch_id' => $line->branch_id,
                    ])->all(),
                ]);
            }

            $expense->delete();
        });
    }

    private function category(ExpenseCategoryEnum|string $category): ExpenseCategoryEnum
    {
        return $category instanceof ExpenseCategoryEnum ? $category : ExpenseCategoryEnum::from($category);
    }

    private function paidFrom(ExpensePaidFromEnum|string $paidFrom): ExpensePaidFromEnum
    {
        return $paidFrom instanceof ExpensePaidFromEnum ? $paidFrom : ExpensePaidFromEnum::from($paidFrom);
    }
}

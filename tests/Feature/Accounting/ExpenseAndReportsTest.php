<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Services\Accounting\AccountingReportService;
use App\Services\Accounting\BooksLockService;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\ExpenseService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExpenseAndReportsTest extends TestCase
{
    use RefreshDatabase;

    private ExpenseService $expenses;

    private AccountingReportService $reports;

    private BooksLockService $booksLock;

    private ChartOfAccountsService $chart;

    private JournalPostingService $posting;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

        $this->expenses = app(ExpenseService::class);
        $this->reports = app(AccountingReportService::class);
        $this->booksLock = app(BooksLockService::class);
        $this->chart = app(ChartOfAccountsService::class);
        $this->posting = app(JournalPostingService::class);
        $this->organization = $this->createOrganization();
        $this->chart->ensureChartOfAccounts($this->organization->getKey());
    }

    public function test_recording_an_expense_posts_a_balanced_split_of_net_and_vat(): void
    {
        $expense = $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-01',
            'category' => ExpenseCategoryEnum::Utilities,
            'amount' => 115,
            'vat_amount' => 15,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]);

        $entry = JournalEntry::query()->find($expense->journal_entry_id)->load('lines');

        // Dr utilities 100 (net) + Dr input VAT 15 / Cr cash 115 (gross).
        $this->assertSame(3, $entry->lines->count());
        $this->assertEquals('100.00', $this->lineFor($entry, SystemAccountEnum::Utilities)->debit);
        $this->assertEquals('15.00', $this->lineFor($entry, SystemAccountEnum::InputVat)->debit);
        $this->assertEquals('115.00', $this->lineFor($entry, SystemAccountEnum::Cash)->credit);
    }

    public function test_trial_balance_sums_to_zero_after_postings(): void
    {
        $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-01',
            'category' => ExpenseCategoryEnum::Rent,
            'amount' => 500,
            'paid_from' => ExpensePaidFromEnum::Bank,
        ]);
        $this->postManualSale(1000, 150);

        $trial = $this->reports->trialBalance($this->organization->getKey());

        // The books must balance: total debit equals total credit.
        $this->assertTrue($trial['balanced']);
        $this->assertSame($trial['total_debit'], $trial['total_credit']);
    }

    public function test_income_statement_nets_revenue_against_expenses(): void
    {
        $this->postManualSale(1000, 0);
        $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-05',
            'category' => ExpenseCategoryEnum::Payroll,
            'amount' => 300,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]);

        $statement = $this->reports->incomeStatement($this->organization->getKey());

        $this->assertEquals(1000.0, $statement['total_revenue']);
        $this->assertEquals(300.0, $statement['total_expenses']);
        $this->assertEquals(700.0, $statement['net_income']);
    }

    public function test_balance_sheet_balances_assets_against_liabilities_plus_equity(): void
    {
        $this->postManualSale(1000, 150);

        $sheet = $this->reports->balanceSheet($this->organization->getKey());

        $this->assertTrue($sheet['balanced']);
    }

    public function test_vat_return_reports_output_minus_input_vat(): void
    {
        // A sale generating 150 output VAT, and an expense with 15 recoverable input VAT.
        $this->postManualSale(1000, 150);
        $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-05',
            'category' => ExpenseCategoryEnum::Supplies,
            'amount' => 115,
            'vat_amount' => 15,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]);

        $vat = $this->reports->vatReturn($this->organization->getKey());

        $this->assertEquals(150.0, $vat['output_vat']);
        $this->assertEquals(15.0, $vat['input_vat']);
        $this->assertEquals(135.0, $vat['net_vat_due']);
    }

    public function test_the_ledger_carries_a_running_balance(): void
    {
        $ar = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::AccountsReceivable);
        $this->postManualSale(1000, 0);
        $this->postManualSale(500, 0);

        $ledger = $this->reports->ledger($ar);

        // Receivables is debit-normal; two sales leave a running balance of 1500.
        $this->assertEquals(1500.0, $ledger['closing_balance']);
        $this->assertCount(2, $ledger['rows']);
    }

    public function test_deleting_an_expense_reverses_the_ledger_instead_of_erasing_it(): void
    {
        $expense = $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-01',
            'category' => ExpenseCategoryEnum::Opex,
            'amount' => 200,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]);

        $this->expenses->reverseAndDelete($expense);

        // The row is gone but both the original and reversing entries remain, so the
        // trial balance still sums to zero and nets to nothing.
        $this->assertNull(Expense::query()->find($expense->getKey()));
        $this->assertSame(2, JournalEntry::query()->where('organization_id', $this->organization->getKey())->count());
        $this->assertTrue($this->reports->trialBalance($this->organization->getKey())['balanced']);
    }

    public function test_the_period_lock_blocks_a_user_dated_expense_inside_the_locked_range(): void
    {
        $this->booksLock->setClosedThrough($this->organization->getKey(), '2026-01-31');

        $this->assertAborts(422, fn () => $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-01-15',
            'category' => ExpenseCategoryEnum::Rent,
            'amount' => 100,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]));
    }

    public function test_the_period_lock_allows_a_date_after_the_locked_range(): void
    {
        $this->booksLock->setClosedThrough($this->organization->getKey(), '2026-01-31');

        $expense = $this->expenses->record([
            'organization_id' => $this->organization->getKey(),
            'date' => '2026-02-01',
            'category' => ExpenseCategoryEnum::Rent,
            'amount' => 100,
            'paid_from' => ExpensePaidFromEnum::Cash,
        ]);

        $this->assertNotNull($expense->getKey());
    }

    public function test_reports_are_isolated_per_organization(): void
    {
        $this->postManualSale(1000, 0);

        $other = $this->createOrganization();
        $this->chart->ensureChartOfAccounts($other->getKey());

        // Another organization's income statement sees none of this one's revenue.
        $this->assertEquals(0.0, $this->reports->incomeStatement($other->getKey())['total_revenue']);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function postManualSale(float $net, float $vat): void
    {
        $ar = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::AccountsReceivable);
        $sales = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::Sales);
        $vatPayable = $this->chart->systemAccount($this->organization->getKey(), SystemAccountEnum::VatPayable);

        $lines = [
            ['account_id' => $ar->getKey(), 'debit' => $net + $vat],
            ['account_id' => $sales->getKey(), 'credit' => $net],
        ];

        if ($vat > 0) {
            $lines[] = ['account_id' => $vatPayable->getKey(), 'credit' => $vat];
        }

        $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'date' => '2026-02-01',
            'lines' => $lines,
        ]);
    }

    private function lineFor(JournalEntry $entry, SystemAccountEnum $key): JournalLine
    {
        $accountId = Account::query()->forOrganization($this->organization->getKey())->systemKey($key)->value('id');

        return $entry->lines->firstWhere('account_id', $accountId);
    }
}

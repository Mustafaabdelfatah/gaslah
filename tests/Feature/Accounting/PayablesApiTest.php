<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\PayableStatusEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Models\Supplier;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\PayablesService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PayablesApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-03 10:00:00');
        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_a_supplier_bill_posts_an_accrual_and_is_returned_in_the_aging_view(): void
    {
        $this->actingAsManager();
        $supplier = Supplier::factory()->create(['organization_id' => $this->organization->getKey()]);

        $response = $this->postJson('/api/payables', [
            'supplier_id' => $supplier->getKey(),
            'amount' => 1150,
            'vat_amount' => 150,
            'category' => 'supplies',
            'issue_date' => '2026-09-01',
            'due_date' => '2026-09-10',
            'bill_no' => 'SUP-14',
            'description' => 'مواد تغليف',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.supplier_name', $supplier->name)
            ->assertJsonPath('data.amount', 1150)
            ->assertJsonPath('data.bill_no', 'SUP-14');

        $payable = Payable::query()->with('expense.journalEntry.lines')->firstOrFail();
        $this->assertSame(ExpensePaidFromEnum::AccountsPayable, $payable->expense->paid_from);
        $this->assertSame(JournalSourceEnum::Expense, $payable->expense->journalEntry->source);
        $this->assertSame('Expense', $payable->expense->journalEntry->ref_type);
        $this->assertEquals(1150, $this->lineAmount($payable->expense->journalEntry, SystemAccountEnum::AccountsPayable, 'credit'));
        $this->assertEquals(150, $this->lineAmount($payable->expense->journalEntry, SystemAccountEnum::InputVat, 'debit'));

        $this->getJson('/api/payables')
            ->assertOk()
            ->assertJsonPath('data.data.0.id', $response->json('data.id'))
            ->assertJsonPath('data.summary.total_open', 1150)
            ->assertJsonPath('data.summary.due_soon', 1150)
            ->assertJsonPath('data.summary.open_count', 1)
            ->assertJsonPath('data.summary.aging.current', 1150);
    }

    public function test_aging_and_paid_this_month_follow_the_reference_buckets(): void
    {
        $this->actingAsManager();

        $overdue = $this->createBill(100, '2026-08-20');
        $this->createBill(200, '2026-09-08');
        $paid = $this->createBill(300, '2026-09-03');
        $this->postJson("/api/payables/{$paid}/pay", ['via' => 'cash'])->assertOk();

        $this->getJson('/api/payables')
            ->assertOk()
            ->assertJsonPath('data.summary.total_open', 300)
            ->assertJsonPath('data.summary.overdue', 100)
            ->assertJsonPath('data.summary.due_soon', 200)
            ->assertJsonPath('data.summary.paid_this_month', 300)
            ->assertJsonPath('data.summary.aging.d1_30', 100)
            ->assertJsonPath('data.summary.aging.current', 200);

        $this->assertNotNull(Payable::find($overdue));
    }

    public function test_paying_a_bill_posts_one_idempotent_settlement(): void
    {
        $this->actingAsManager();
        $id = $this->createBill(575, '2026-09-30');

        $this->postJson("/api/payables/{$id}/pay", [
            'via' => 'bank',
            'date' => '2026-09-04',
        ])->assertOk()
            ->assertJsonPath('data.status', 'paid')
            ->assertJsonPath('data.paid_via', 'bank');

        $payable = Payable::query()->with('paidJournalEntry.lines')->findOrFail($id);
        $this->assertSame(PayableStatusEnum::Paid, $payable->status);
        $this->assertEquals(575, $this->lineAmount($payable->paidJournalEntry, SystemAccountEnum::AccountsPayable, 'debit'));
        $this->assertEquals(575, $this->lineAmount($payable->paidJournalEntry, SystemAccountEnum::Bank, 'credit'));

        $this->postJson("/api/payables/{$id}/pay", ['via' => 'cash'])->assertStatus(422);
        $this->assertSame(1, JournalEntry::query()->where('ref_type', 'PayableSettlement')->count());
    }

    public function test_voiding_an_open_bill_reverses_the_accrual_before_deleting_it(): void
    {
        $this->actingAsManager();
        $id = $this->createBill(400, '2026-09-20');
        $expenseId = Payable::findOrFail($id)->expense_id;

        $this->deleteJson("/api/payables/{$id}")->assertOk();

        $this->assertDatabaseMissing('payables', ['id' => $id]);
        $this->assertDatabaseMissing('expenses', ['id' => $expenseId]);
        $this->assertDatabaseHas('journal_entries', ['ref_type' => 'Expense', 'ref_id' => (string) $expenseId]);
        $this->assertDatabaseHas('journal_entries', ['ref_type' => 'ExpenseReversal', 'ref_id' => (string) $expenseId]);
    }

    public function test_a_paid_bill_cannot_be_voided(): void
    {
        $this->actingAsManager();
        $id = $this->createBill(400, '2026-09-20');
        $expenseId = Payable::findOrFail($id)->expense_id;
        $this->postJson("/api/payables/{$id}/pay", ['via' => 'cash'])->assertOk();

        $this->deleteJson("/api/payables/{$id}")->assertStatus(422);
        $this->deleteJson("/api/accounting/expenses/{$expenseId}")->assertStatus(422);

        $this->assertDatabaseHas('payables', ['id' => $id, 'status' => 'paid']);
        $this->assertDatabaseHas('expenses', ['id' => $expenseId]);
    }

    public function test_a_supplier_from_another_organization_is_rejected(): void
    {
        $this->actingAsManager();
        $foreign = Supplier::factory()->create([
            'organization_id' => $this->createOrganization()->getKey(),
        ]);

        $this->postJson('/api/payables', [
            'supplier_id' => $foreign->getKey(),
            'amount' => 100,
            'category' => 'opex',
            'due_date' => '2026-09-10',
        ])->assertStatus(422);
    }

    public function test_supplier_options_include_their_open_balance_only(): void
    {
        $this->actingAsManager();
        $supplier = Supplier::factory()->create(['organization_id' => $this->organization->getKey()]);
        $open = $this->createBill(250, '2026-09-10', $supplier->getKey());
        $paid = $this->createBill(500, '2026-09-10', $supplier->getKey());
        $this->postJson("/api/payables/{$paid}/pay", ['via' => 'bank'])->assertOk();

        $this->getJson('/api/payables/suppliers')
            ->assertOk()
            ->assertJsonPath('data.0.id', $supplier->getKey())
            ->assertJsonPath('data.0.open_balance', 250);

        $this->assertNotNull(Payable::find($open));
    }

    public function test_a_monthly_template_materializes_at_the_scheduled_date_and_clamps_anchor_day(): void
    {
        $this->actingAsManager();

        $templateId = $this->postJson('/api/payables/recurring', [
            'name' => 'إيجار الفرع',
            'category' => 'rent',
            'amount' => 2300,
            'vat_amount' => 300,
            'paid_from' => 'ap',
            'frequency' => 'monthly',
            'anchor_day' => 31,
            'due_days' => 15,
            'start_date' => '2026-01-31',
        ])->assertCreated()
            ->assertJsonPath('data.next_run', '2026-01-31')
            ->json('data.id');

        $this->postJson("/api/payables/recurring/{$templateId}/run")
            ->assertOk()
            ->assertJsonPath('data.generated.type', 'bill');

        $template = RecurringBill::findOrFail($templateId);
        $bill = Payable::query()->where('recurring_bill_id', $templateId)->firstOrFail();
        $this->assertSame('2026-01-31', $bill->issue_date->toDateString());
        $this->assertSame('2026-02-15', $bill->due_date->toDateString());
        $this->assertSame('2026-02-28', $template->next_run->toDateString());
        $this->assertSame(1, $template->generated_count);
    }

    public function test_a_cash_template_generates_a_directly_paid_expense_not_a_payable(): void
    {
        $this->actingAsManager();

        $templateId = $this->createTemplate([
            'paid_from' => 'cash',
            'frequency' => 'weekly',
            'start_date' => '2026-09-03',
        ]);

        $expenseId = $this->postJson("/api/payables/recurring/{$templateId}/run")
            ->assertOk()
            ->assertJsonPath('data.generated.type', 'expense')
            ->json('data.generated.id');

        $this->assertSame(ExpensePaidFromEnum::Cash, Expense::findOrFail($expenseId)->paid_from);
        $this->assertDatabaseMissing('payables', ['expense_id' => $expenseId]);
        $this->assertSame('2026-09-10', RecurringBill::findOrFail($templateId)->next_run->toDateString());
    }

    public function test_due_runner_catches_up_each_missed_period_once(): void
    {
        $this->actingAsManager();
        $templateId = $this->createTemplate([
            'start_date' => '2026-07-31',
            'anchor_day' => 31,
            'due_days' => 0,
        ]);

        $generated = app(PayablesService::class)->runDue($this->organization->getKey());

        $this->assertSame(2, $generated);
        $this->assertSame(2, Payable::query()->where('recurring_bill_id', $templateId)->count());
        $this->assertSame('2026-09-30', RecurringBill::findOrFail($templateId)->next_run->toDateString());
    }

    public function test_recurring_templates_can_be_updated_and_deleted(): void
    {
        $this->actingAsManager();
        $templateId = $this->createTemplate();

        $this->putJson("/api/payables/recurring/{$templateId}", [
            'name' => 'فاتورة كهرباء محدثة',
            'category' => 'utilities',
            'amount' => 900,
            'paid_from' => 'bank',
            'frequency' => 'yearly',
            'anchor_day' => 5,
            'due_days' => 0,
            'branch_id' => null,
            'start_date' => '2027-01-05',
            'is_active' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'فاتورة كهرباء محدثة')
            ->assertJsonPath('data.next_run', '2027-01-05')
            ->assertJsonPath('data.branch_id', null)
            ->assertJsonPath('data.is_active', false);

        $this->deleteJson("/api/payables/recurring/{$templateId}")->assertOk();
        $this->assertDatabaseMissing('recurring_bills', ['id' => $templateId]);
    }

    public function test_payables_are_manager_only_and_foreign_records_are_hidden(): void
    {
        $cashier = $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        $this->actingAsStaff($cashier);
        $this->getJson('/api/payables')->assertForbidden();

        [$foreignOrganization, $foreignBranch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($foreignOrganization->getKey());
        $foreignManager = $this->createStaff($foreignBranch, StaffRoleEnum::SuperAdmin);
        $this->actingAsStaff($foreignManager);
        $foreignId = $this->createBill(100, '2026-09-10');

        $this->actingAsManager();
        $this->postJson("/api/payables/{$foreignId}/pay", ['via' => 'cash'])->assertNotFound();
        $this->deleteJson("/api/payables/{$foreignId}")->assertNotFound();
    }

    private function actingAsManager(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
    }

    private function createBill(float $amount, string $dueDate, ?int $supplierId = null): int
    {
        return $this->postJson('/api/payables', [
            'supplier_id' => $supplierId,
            'amount' => $amount,
            'category' => 'opex',
            'due_date' => $dueDate,
        ])->assertCreated()->json('data.id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTemplate(array $overrides = []): int
    {
        return $this->postJson('/api/payables/recurring', array_merge([
            'name' => 'مصروف متكرر',
            'category' => 'utilities',
            'amount' => 700,
            'paid_from' => 'ap',
            'frequency' => 'monthly',
            'anchor_day' => 31,
            'due_days' => 10,
            'start_date' => '2026-09-03',
        ], $overrides))->assertCreated()->json('data.id');
    }

    private function lineAmount(JournalEntry $entry, SystemAccountEnum $account, string $side): float
    {
        $accountId = Account::query()
            ->where('organization_id', $entry->organization_id)
            ->where('system_key', $account->value)
            ->value('id');

        return (float) $entry->lines->firstWhere('account_id', $accountId)?->{$side};
    }
}

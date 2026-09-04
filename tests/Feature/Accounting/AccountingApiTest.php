<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Account;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\Order;
use App\Models\Organization;
use App\Models\User;
use App\Services\Accounting\AccountingReportService;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AccountingApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(FeatureSeeder::class);
        [$this->organization, $this->branch] = $this->createTenant();
    }

    public function test_the_chart_of_accounts_is_seeded_on_first_view(): void
    {
        $this->actingAsManager();

        $response = $this->getJson('/api/accounting/accounts');

        $response->assertOk();
        // The 16 core accounts are seeded lazily the first time the chart is read.
        $this->assertCount(16, $response->json('data'));
    }

    public function test_a_manager_can_post_a_balanced_manual_journal(): void
    {
        $this->actingAsManager();
        $this->getJson('/api/accounting/accounts'); // seed the chart

        $cash = $this->systemAccount(SystemAccountEnum::Cash);
        $capital = $this->systemAccount(SystemAccountEnum::Capital);

        $response = $this->postJson('/api/accounting/journal', [
            'date' => '2026-03-01',
            'memo' => 'Owner capital injection',
            'lines' => [
                ['account_id' => $cash, 'debit' => 5000],
                ['account_id' => $capital, 'credit' => 5000],
            ],
        ]);

        $response->assertCreated()->assertJsonPath('data.entry_no', 1);
        $this->assertCount(2, $response->json('data.lines'));
    }

    public function test_an_unbalanced_manual_journal_is_refused(): void
    {
        $this->actingAsManager();
        $this->getJson('/api/accounting/accounts');

        $cash = $this->systemAccount(SystemAccountEnum::Cash);
        $capital = $this->systemAccount(SystemAccountEnum::Capital);

        // An unbalanced entry is the caller's error: rejected as 422, nothing created.
        $this->postJson('/api/accounting/journal', [
            'lines' => [
                ['account_id' => $cash, 'debit' => 5000],
                ['account_id' => $capital, 'credit' => 4000],
            ],
        ])->assertStatus(422);

        $this->assertSame(0, JournalEntry::query()->count());
    }

    public function test_a_journal_referencing_a_foreign_account_is_refused(): void
    {
        $this->actingAsManager();
        $this->getJson('/api/accounting/accounts');
        $cash = $this->systemAccount(SystemAccountEnum::Cash);

        // An account from another organization must not be postable to.
        $foreign = Account::factory()->create();

        $this->postJson('/api/accounting/journal', [
            'lines' => [
                ['account_id' => $cash, 'debit' => 100],
                ['account_id' => $foreign->getKey(), 'credit' => 100],
            ],
        ])->assertStatus(422);
    }

    public function test_the_trial_balance_reports_through_the_api(): void
    {
        $this->actingAsManager();
        $this->getJson('/api/accounting/accounts');
        $cash = $this->systemAccount(SystemAccountEnum::Cash);
        $capital = $this->systemAccount(SystemAccountEnum::Capital);

        $this->postJson('/api/accounting/journal', [
            'lines' => [
                ['account_id' => $cash, 'debit' => 5000],
                ['account_id' => $capital, 'credit' => 5000],
            ],
        ])->assertCreated();

        $this->getJson('/api/accounting/trial-balance')
            ->assertOk()
            ->assertJsonPath('data.balanced', true);
    }

    public function test_the_overview_carries_cumulative_positions_and_period_movement(): void
    {
        $this->actingAsManager();
        $this->getJson('/api/accounting/accounts');
        $cash = $this->systemAccount(SystemAccountEnum::Cash);
        $capital = $this->systemAccount(SystemAccountEnum::Capital);

        $this->postJson('/api/accounting/journal', [
            'lines' => [
                ['account_id' => $cash, 'debit' => 5000],
                ['account_id' => $capital, 'credit' => 5000],
            ],
        ])->assertCreated();

        $response = $this->getJson('/api/accounting/overview')->assertOk();

        // Capital is neither revenue nor expense, so only the cash position moves.
        $response->assertJsonPath('data.cash', 5000)
            ->assertJsonPath('data.revenue', 0)
            ->assertJsonPath('data.expenses', 0)
            ->assertJsonPath('data.net_income', 0);

        // Nothing was ever booked to the bank account, so it reads as absent.
        $this->assertNull($response->json('data.bank'));
    }

    public function test_receivables_age_each_customer_by_their_oldest_unpaid_order(): void
    {
        $this->actingAsManager();
        $customer = Customer::factory()->create(['organization_id' => $this->organization->getKey()]);

        // Two debts of the same customer, one fresh and one long overdue.
        Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $customer->getKey(),
            'grand_total' => 100,
            'paid_total' => 40,
            'payment_status' => PaymentStatusEnum::Partial->value,
            'created_at' => Carbon::now()->subDays(5),
        ]);
        Order::factory()->create([
            'organization_id' => $this->organization->getKey(),
            'branch_id' => $this->branch->getKey(),
            'customer_id' => $customer->getKey(),
            'grand_total' => 200,
            'paid_total' => 0,
            'payment_status' => PaymentStatusEnum::Unpaid->value,
            'created_at' => Carbon::now()->subDays(95),
        ]);

        $response = $this->getJson('/api/accounting/receivables')->assertOk();

        $response->assertJsonPath('data.total', 260)
            // Each order lands in its own bucket…
            ->assertJsonPath('data.buckets.current', 60)
            ->assertJsonPath('data.buckets.d90', 200)
            // …while the customer row is aged by their oldest debt.
            ->assertJsonPath('data.customers.0.due', 260)
            ->assertJsonPath('data.customers.0.orders_count', 2)
            ->assertJsonPath('data.customers.0.bucket', 'd90');

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AccountingReportService::class)->receivables(
            $this->organization->getKey(),
            [$this->branch->getKey()],
        );
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries, 'Receivables must aggregate in SQL instead of hydrating every unpaid order.');
    }

    public function test_a_manager_can_record_an_expense_and_it_posts(): void
    {
        $this->actingAsManager();

        $this->postJson('/api/accounting/expenses', [
            'date' => '2026-03-05',
            'category' => 'rent',
            'amount' => 2000,
            'paid_from' => 'bank',
        ])->assertCreated()->assertJsonPath('data.category', 'rent');
    }

    public function test_a_cashier_cannot_reach_accounting(): void
    {
        $cashier = $this->createStaff($this->branch, StaffRoleEnum::Cashier);
        $this->actingAsStaff($cashier);

        // Accounting is manager-gated; a cashier is refused.
        $this->getJson('/api/accounting/accounts')->assertStatus(403);
    }

    public function test_only_the_general_manager_can_change_the_period_lock(): void
    {
        $manager = $this->createStaff($this->branch, StaffRoleEnum::BranchManager);
        $this->actingAsStaff($manager);

        // A branch manager may view accounting but not reopen the books.
        $this->putJson('/api/accounting/period-lock', ['closed_through' => '2026-01-31'])
            ->assertStatus(403);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function actingAsManager(): User
    {
        $manager = $this->createStaff($this->branch, StaffRoleEnum::SuperAdmin);

        return $this->actingAsStaff($manager);
    }

    private function systemAccount(SystemAccountEnum $key): int
    {
        return Account::query()->forOrganization($this->organization->getKey())->systemKey($key)->value('id');
    }
}

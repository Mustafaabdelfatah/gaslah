<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Account;
use App\Models\Branch;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\FeatureSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

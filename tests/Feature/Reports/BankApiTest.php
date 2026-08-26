<?php

namespace Tests\Feature\Reports;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Branch;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Tests\TestCase;

class BankApiTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->organization, $this->branch] = $this->createTenant();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
    }

    public function test_the_reconciliation_reports_the_book_balance(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->bankEntry(500);
        $this->bankEntry(300);

        $this->getJson('/api/bank/reconciliation')
            ->assertOk()
            ->assertJsonPath('data.summary.book_balance', 800)
            ->assertJsonPath('data.summary.cleared_balance', 0)
            ->assertJsonPath('data.summary.line_count', 2);
    }

    public function test_clearing_lines_and_setting_the_statement_reconciles(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->bankEntry(500);
        $lineId = $this->bankLineIds()->first();

        $this->postJson('/api/bank/clear', ['line_id' => $lineId, 'cleared' => true])
            ->assertOk()
            ->assertJsonPath('data.summary.cleared_balance', 500);

        $this->postJson('/api/bank/statement-balance', ['balance' => 500])
            ->assertOk()
            ->assertJsonPath('data.summary.difference', 0)
            ->assertJsonPath('data.summary.reconciled', true);
    }

    public function test_clear_all_marks_every_line(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::SuperAdmin));
        $this->bankEntry(100);
        $this->bankEntry(200);

        $this->postJson('/api/bank/clear-all', ['cleared' => true])
            ->assertOk()
            ->assertJsonPath('data.summary.cleared_balance', 300)
            ->assertJsonPath('data.summary.cleared_count', 2);
    }

    public function test_a_cashier_cannot_reconcile(): void
    {
        $this->actingAsStaff($this->createStaff($this->branch, StaffRoleEnum::Cashier));

        $this->getJson('/api/bank/reconciliation')->assertStatus(403);
    }

    private function bankEntry(float $amount): void
    {
        $chart = app(ChartOfAccountsService::class);
        app(JournalPostingService::class)->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'ref_type' => 'Test',
            'ref_id' => uniqid(),
            'branch_id' => $this->branch->getKey(),
            'lines' => [
                ['account_id' => $chart->systemAccount($this->organization->getKey(), SystemAccountEnum::Bank)->getKey(), 'debit' => $amount],
                ['account_id' => $chart->systemAccount($this->organization->getKey(), SystemAccountEnum::AccountsReceivable)->getKey(), 'credit' => $amount],
            ],
        ]);
    }

    private function bankLineIds(): Collection
    {
        $bankId = app(ChartOfAccountsService::class)->systemAccount($this->organization->getKey(), SystemAccountEnum::Bank)->getKey();

        return JournalLine::query()->where('account_id', $bankId)->pluck('id');
    }
}

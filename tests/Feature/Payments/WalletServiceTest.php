<?php

namespace Tests\Feature\Payments;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Account;
use App\Models\Customer;
use App\Models\JournalEntry;
use App\Models\JournalLine;
use App\Models\Organization;
use App\Models\WalletTransaction;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Payments\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletServiceTest extends TestCase
{
    use RefreshDatabase;

    private WalletService $wallet;

    private Organization $organization;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->wallet = app(WalletService::class);
        $this->organization = $this->createOrganization();
        app(ChartOfAccountsService::class)->ensureChartOfAccounts($this->organization->getKey());
        $this->customer = Customer::factory()->create(['organization_id' => $this->organization->getKey()]);
    }

    public function test_a_topup_raises_the_balance_and_records_balance_after(): void
    {
        $result = $this->wallet->credit($this->customer, 100, WalletTransactionTypeEnum::Topup, 'Cash top-up');

        $this->assertEquals(100.0, $result['balance']);
        $this->assertEquals('100.00', $this->customer->fresh()->wallet_balance);

        // balance_after captures the running balance under the lock.
        $this->assertEquals('100.00', WalletTransaction::query()->find($result['transaction_id'])->balance_after);
    }

    public function test_a_topup_posts_cash_against_deferred_revenue(): void
    {
        $this->wallet->credit($this->customer, 100, WalletTransactionTypeEnum::Topup, 'Cash top-up');

        $entry = JournalEntry::query()
            ->where('organization_id', $this->organization->getKey())
            ->where('source', JournalSourceEnum::WalletTopup->value)
            ->with('lines')->first();

        // Received cash is a deferred-revenue liability, not recognised revenue.
        $this->assertEquals('100.00', $this->line($entry, SystemAccountEnum::Cash)->debit);
        $this->assertEquals('100.00', $this->line($entry, SystemAccountEnum::DeferredRevenue)->credit);
    }

    public function test_a_debit_lowers_the_balance(): void
    {
        $this->wallet->credit($this->customer, 100, WalletTransactionTypeEnum::Topup, 'Top-up');

        $result = $this->wallet->debit($this->customer->fresh(), 30, 'Order payment');

        $this->assertEquals(70.0, $result['balance']);
        $this->assertEquals('70.00', $this->customer->fresh()->wallet_balance);
    }

    public function test_a_debit_beyond_the_balance_is_refused(): void
    {
        $this->wallet->credit($this->customer, 50, WalletTransactionTypeEnum::Topup, 'Top-up');

        // The sufficiency check runs against the locked balance.
        $this->assertAborts(422, fn () => $this->wallet->debit($this->customer->fresh(), 80, 'Overspend'));
        $this->assertEquals('50.00', $this->customer->fresh()->wallet_balance);
    }

    public function test_a_refund_restores_value_without_posting_to_the_ledger(): void
    {
        // A refund credits the wallet but posts no entry — the caller records the
        // matching liability move itself, so postAccounting stays false.
        $this->wallet->credit($this->customer, 40, WalletTransactionTypeEnum::Refund, 'Order refund', postAccounting: false);

        $this->assertEquals('40.00', $this->customer->fresh()->wallet_balance);
        $this->assertSame(0, JournalEntry::query()->where('source', JournalSourceEnum::WalletTopup->value)->count());
    }

    public function test_a_zero_or_negative_movement_is_refused(): void
    {
        $this->assertAborts(422, fn () => $this->wallet->credit($this->customer, 0, WalletTransactionTypeEnum::Topup, 'Zero'));
        $this->assertAborts(422, fn () => $this->wallet->debit($this->customer, -5, 'Negative'));
    }

    public function test_balance_after_tracks_each_movement_in_order(): void
    {
        $this->wallet->credit($this->customer, 100, WalletTransactionTypeEnum::Topup, 'A');
        $this->wallet->debit($this->customer->fresh(), 30, 'B');
        $this->wallet->credit($this->customer->fresh(), 10, WalletTransactionTypeEnum::Topup, 'C');

        $balances = WalletTransaction::query()
            ->where('customer_id', $this->customer->getKey())
            ->orderBy('id')
            ->pluck('balance_after')
            ->map(fn ($value) => (float) $value)
            ->all();

        $this->assertSame([100.0, 70.0, 80.0], $balances);
    }

    private function line(JournalEntry $entry, SystemAccountEnum $key): JournalLine
    {
        $accountId = Account::query()->forOrganization($this->organization->getKey())->systemKey($key)->value('id');

        return $entry->lines->firstWhere('account_id', $accountId);
    }
}

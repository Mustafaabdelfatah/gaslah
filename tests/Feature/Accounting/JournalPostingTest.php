<?php

namespace Tests\Feature\Accounting;

use App\Enum\Accounting\AccountTypeEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Organization;
use App\Services\Accounting\AccountingException;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalPostingTest extends TestCase
{
    use RefreshDatabase;

    private JournalPostingService $posting;

    private Organization $organization;

    private Account $cash;

    private Account $sales;

    protected function setUp(): void
    {
        parent::setUp();

        $this->posting = app(JournalPostingService::class);
        $this->organization = $this->createOrganization();
        $this->cash = $this->account(AccountTypeEnum::Asset);
        $this->sales = $this->account(AccountTypeEnum::Revenue);
    }

    public function test_a_balanced_entry_is_posted_with_its_lines(): void
    {
        $entry = $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'memo' => 'Opening cash sale',
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 100],
                ['account_id' => $this->sales->getKey(), 'credit' => 100],
            ],
        ]);

        $this->assertSame(1, $entry->entry_no);
        $this->assertCount(2, $entry->lines);
        $this->assertEquals('100.00', $entry->lines->firstWhere('account_id', $this->cash->getKey())->debit);
    }

    public function test_an_unbalanced_entry_is_refused(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('UNBALANCED_ENTRY');

        $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 100],
                ['account_id' => $this->sales->getKey(), 'credit' => 99.99],
            ],
        ]);
    }

    public function test_balance_is_checked_in_halalas_not_floating_point(): void
    {
        // 0.1 + 0.2 famously is not 0.3 in float; the halalas comparison must accept it.
        $entry = $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 0.1],
                ['account_id' => $this->cash->getKey(), 'debit' => 0.2],
                ['account_id' => $this->sales->getKey(), 'credit' => 0.3],
            ],
        ]);

        $this->assertNotNull($entry->getKey());
    }

    public function test_zero_lines_are_dropped_before_the_minimum_is_checked(): void
    {
        // Two real lines plus a zero one: the zero is dropped, two survive, it posts.
        $entry = $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 50],
                ['account_id' => $this->sales->getKey(), 'credit' => 50],
                ['account_id' => $this->sales->getKey(), 'debit' => 0, 'credit' => 0],
            ],
        ]);

        $this->assertCount(2, $entry->lines);
    }

    public function test_an_entry_with_fewer_than_two_lines_is_refused(): void
    {
        $this->expectException(AccountingException::class);
        $this->expectExceptionMessage('ENTRY_NEEDS_TWO_LINES');

        $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 100],
                ['account_id' => $this->sales->getKey(), 'debit' => 0, 'credit' => 0],
            ],
        ]);
    }

    public function test_entry_numbers_are_sequential_per_organization(): void
    {
        $other = $this->createOrganization();
        $otherCash = $this->account(AccountTypeEnum::Asset, $other);
        $otherSales = $this->account(AccountTypeEnum::Revenue, $other);

        $first = $this->postSale($this->cash, $this->sales, 10);
        $second = $this->postSale($this->cash, $this->sales, 20);
        $otherFirst = $this->postSale($otherCash, $otherSales, 30, $other);

        $this->assertSame(1, $first->entry_no);
        $this->assertSame(2, $second->entry_no);

        // A separate organization keeps its own sequence starting at 1.
        $this->assertSame(1, $otherFirst->entry_no);
    }

    public function test_reposting_the_same_reference_returns_the_existing_entry(): void
    {
        $input = [
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Order,
            'ref_type' => 'Order',
            'ref_id' => 42,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 100],
                ['account_id' => $this->sales->getKey(), 'credit' => 100],
            ],
        ];

        $first = $this->posting->post($input);
        $second = $this->posting->post($input);

        // Idempotent: the second call returns the same entry, and no duplicate exists.
        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, JournalEntry::query()->where('organization_id', $this->organization->getKey())->count());
    }

    public function test_manual_entries_with_no_reference_may_repeat(): void
    {
        $input = [
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 5],
                ['account_id' => $this->sales->getKey(), 'credit' => 5],
            ],
        ];

        $this->posting->post($input);
        $this->posting->post($input);

        // No ref_id means no idempotency guard, so both legitimately post.
        $this->assertSame(2, JournalEntry::query()->where('organization_id', $this->organization->getKey())->count());
    }

    public function test_an_entry_is_dated_by_its_source_document_not_the_moment_of_posting(): void
    {
        $entry = $this->posting->post([
            'organization_id' => $this->organization->getKey(),
            'source' => JournalSourceEnum::Order,
            'ref_type' => 'Order',
            'ref_id' => 7,
            'date' => '2026-01-15',
            'lines' => [
                ['account_id' => $this->cash->getKey(), 'debit' => 100],
                ['account_id' => $this->sales->getKey(), 'credit' => 100],
            ],
        ]);

        $this->assertSame('2026-01-15', $entry->date->toDateString());
    }

    public function test_a_reversal_mirrors_every_line(): void
    {
        $entry = $this->postSale($this->cash, $this->sales, 100);

        $reversal = $this->posting->reverse($entry);

        // Debit and credit are swapped on each line.
        $this->assertEquals('100.00', $reversal->lines->firstWhere('account_id', $this->cash->getKey())->credit);
        $this->assertEquals('100.00', $reversal->lines->firstWhere('account_id', $this->sales->getKey())->debit);
    }

    public function test_reversing_the_same_entry_twice_returns_the_existing_reversal(): void
    {
        $entry = $this->postSale($this->cash, $this->sales, 100);

        $first = $this->posting->reverse($entry);
        $second = $this->posting->reverse($entry);

        $this->assertSame($first->getKey(), $second->getKey());
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function account(AccountTypeEnum $type, ?Organization $organization = null): Account
    {
        return Account::factory()->type($type)->create([
            'organization_id' => ($organization ?? $this->organization)->getKey(),
        ]);
    }

    private function postSale(Account $debit, Account $credit, float $amount, ?Organization $organization = null): JournalEntry
    {
        return $this->posting->post([
            'organization_id' => ($organization ?? $this->organization)->getKey(),
            'source' => JournalSourceEnum::Manual,
            'lines' => [
                ['account_id' => $debit->getKey(), 'debit' => $amount],
                ['account_id' => $credit->getKey(), 'credit' => $amount],
            ],
        ]);
    }
}

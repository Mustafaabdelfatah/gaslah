<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Enum\Accounting\JournalSourceEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\StoreJournalRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Accounting\JournalEntryResource;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Services\Accounting\BooksLockService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Http\JsonResponse;

class JournalController extends TenantController
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly BooksLockService $booksLock,
    ) {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->requireManager();

        $query = JournalEntry::query()
            ->where('organization_id', $this->organizationId())
            ->with('lines')
            ->when($request->filled('source'), fn ($q) => $q->where('source', $request->input('source')))
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->input('to')))
            ->latest('entry_no');

        return successResponse(wrapPaginate($query, JournalEntryResource::class));
    }

    public function show(JournalEntry $journal): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($journal);

        return successResponse($journal->load('lines'));
    }

    /**
     * Post a manual journal entry.
     */
    public function store(StoreJournalRequest $request): JsonResponse
    {
        $this->requireManager();

        $organizationId = $this->organizationId();
        $data = $request->validated();

        $this->assertAccountsOwned($organizationId, collect($data['lines'])->pluck('account_id')->all());

        // The user chooses the date, so the period lock applies.
        $this->booksLock->assertOpen($organizationId, $data['date'] ?? null);

        $this->assertHasPositiveDebit($data['lines']);

        $entry = $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Manual,
            'date' => $data['date'] ?? null,
            'memo' => $data['memo'] ?? null,
            'branch_id' => $data['branch_id'] ?? null,
            'created_by_id' => $this->staff()->getKey(),
            'lines' => $data['lines'],
        ]);

        return successResponse($entry, __('api.created_success'), 201);
    }

    /**
     * Correct an entry by posting its reversal.
     */
    public function reverse(JournalEntry $journal): JsonResponse
    {
        $this->requireManager();
        $this->assertOwned($journal);

        $reversal = $this->posting->reverse($journal, $this->staff()->getKey());

        return successResponse($reversal->load('lines'), __('api.created_success'), 201);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, int>  $accountIds
     */
    private function assertAccountsOwned(int $organizationId, array $accountIds): void
    {
        $owned = Account::query()
            ->where('organization_id', $organizationId)
            ->whereIn('id', $accountIds)
            ->count();

        abort_unless($owned === count(array_unique($accountIds)), 422, __('api.account_not_owned'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function assertHasPositiveDebit(array $lines): void
    {
        $totalDebit = collect($lines)->sum(fn ($line) => (float) ($line['debit'] ?? 0));

        abort_unless($totalDebit > 0, 422, __('api.entry_needs_two_lines'));
    }
}

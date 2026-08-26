<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\JournalSourceEnum;
use App\Models\Account;
use App\Models\JournalEntry;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The single gate through which every journal entry is written.
 *
 * Nothing else inserts into journal_entries: routing all posting through here is what
 * guarantees the four money invariants — every entry balances, carries a sequential
 * number, is dated by its source document, and is idempotent on
 * (organization, source, ref_type, ref_id).
 */
class JournalPostingService
{
    /**
     * Retries for the two collision points that can occur under concurrency: the
     * per-organization entry number and the idempotency key.
     */
    private const MAX_ATTEMPTS = 8;

    /**
     * Post a balanced journal entry.
     *
     * Re-posting the same source reference returns the entry that already exists
     * rather than writing a second one, which is what makes the accounting sync and
     * the backfill command safe to run repeatedly.
     *
     * @param  array{
     *     organization_id: int,
     *     source: JournalSourceEnum|string,
     *     lines: array<int, array{account_id?: int, account?: Account, debit?: float|int, credit?: float|int, memo?: string, branch_id?: int}>,
     *     date?: CarbonInterface|string|null,
     *     memo?: string|null,
     *     ref_type?: string|null,
     *     ref_id?: string|int|null,
     *     branch_id?: int|null,
     *     created_by_id?: int|null,
     * }  $input
     */
    public function post(array $input): JournalEntry
    {
        $organizationId = $input['organization_id'];
        $source = $this->normalizeSource($input['source']);
        $refType = $input['ref_type'] ?? null;
        $refId = isset($input['ref_id']) ? (string) $input['ref_id'] : null;

        $lines = $this->prepareLines($input['lines'] ?? []);
        $this->assertBalanced($lines);

        $date = $this->resolveDate($input['date'] ?? null);

        // An idempotent post short-circuits before touching the sequence, so a
        // duplicate call is cheap and side-effect free.
        if ($refId !== null) {
            $existing = $this->findExisting($organizationId, $source, $refType, $refId);

            if ($existing !== null) {
                return $existing;
            }
        }

        return $this->writeWithRetry($organizationId, $source, $refType, $refId, $date, $lines, $input);
    }

    /**
     * Correct an entry by posting its mirror image.
     *
     * System entries are never edited or deleted; a reversal is the only correction,
     * and reversing the same entry twice returns the existing reversal.
     */
    public function reverse(JournalEntry $entry, ?int $createdById = null): JournalEntry
    {
        $entry->loadMissing('lines');

        $reversedLines = $entry->lines->map(fn ($line) => [
            'account_id' => $line->account_id,
            'debit' => (float) $line->credit,
            'credit' => (float) $line->debit,
            'branch_id' => $line->branch_id,
            'memo' => $line->memo,
        ])->all();

        return $this->post([
            'organization_id' => $entry->organization_id,
            'source' => JournalSourceEnum::Manual,
            'ref_type' => 'JournalReversal',
            'ref_id' => $entry->getKey(),
            'date' => $entry->date,
            'memo' => __('api.journal_reversal_of', ['entry_no' => $entry->entry_no]),
            'branch_id' => $entry->branch_id,
            'created_by_id' => $createdById,
            'lines' => $reversedLines,
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Round each side, drop lines that are zero on both sides, and require at least
     * two survivors.
     *
     * @param  array<int, array<string, mixed>>  $rawLines
     * @return array<int, array{account_id: int, debit: float, credit: float, branch_id: int|null, memo: string|null}>
     */
    private function prepareLines(array $rawLines): array
    {
        $lines = [];

        foreach ($rawLines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);

            if ($debit === 0.0 && $credit === 0.0) {
                continue;
            }

            $accountId = $line['account_id'] ?? ($line['account'] ?? null)?->getKey();

            $lines[] = [
                'account_id' => (int) $accountId,
                'debit' => $debit,
                'credit' => $credit,
                'branch_id' => $line['branch_id'] ?? null,
                'memo' => $line['memo'] ?? null,
            ];
        }

        if (count($lines) < 2) {
            throw AccountingException::needsTwoLines();
        }

        return $lines;
    }

    /**
     * Compare the two sides in integer halalas, avoiding the float representation
     * errors that a direct decimal comparison would risk.
     *
     * @param  array<int, array{debit: float, credit: float}>  $lines
     */
    private function assertBalanced(array $lines): void
    {
        $debit = 0;
        $credit = 0;

        foreach ($lines as $line) {
            $debit += (int) round($line['debit'] * 100);
            $credit += (int) round($line['credit'] * 100);
        }

        if ($debit !== $credit) {
            throw AccountingException::unbalanced($debit, $credit);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function writeWithRetry(
        int $organizationId,
        JournalSourceEnum $source,
        ?string $refType,
        ?string $refId,
        Carbon $date,
        array $lines,
        array $input
    ): JournalEntry {
        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return DB::transaction(function () use ($organizationId, $source, $refType, $refId, $date, $lines, $input) {
                    $entry = JournalEntry::query()->create([
                        'organization_id' => $organizationId,
                        'entry_no' => $this->nextEntryNo($organizationId),
                        'date' => $date,
                        'memo' => $input['memo'] ?? null,
                        'source' => $source->value,
                        'ref_type' => $refType,
                        'ref_id' => $refId,
                        'branch_id' => $input['branch_id'] ?? null,
                        'created_by_id' => $input['created_by_id'] ?? null,
                    ]);

                    foreach ($lines as $line) {
                        $entry->lines()->create([
                            'organization_id' => $organizationId,
                            'account_id' => $line['account_id'],
                            'debit' => $line['debit'],
                            'credit' => $line['credit'],
                            'branch_id' => $line['branch_id'] ?? $input['branch_id'] ?? null,
                            'memo' => $line['memo'],
                        ]);
                    }

                    return $entry->load('lines');
                });
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception) || $attempt >= self::MAX_ATTEMPTS) {
                    throw $exception;
                }

                // Either the entry number raced or a concurrent caller posted the same
                // reference first. If it was the reference, return the winner; otherwise
                // recompute the number and try again.
                if ($refId !== null) {
                    $existing = $this->findExisting($organizationId, $source, $refType, $refId);

                    if ($existing !== null) {
                        return $existing;
                    }
                }
            } catch (Throwable $exception) {
                throw $exception;
            }
        }
    }

    private function nextEntryNo(int $organizationId): int
    {
        $max = JournalEntry::query()
            ->where('organization_id', $organizationId)
            ->max('entry_no');

        return (int) $max + 1;
    }

    private function findExisting(int $organizationId, JournalSourceEnum $source, ?string $refType, string $refId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('organization_id', $organizationId)
            ->where('source', $source->value)
            ->where('ref_type', $refType)
            ->where('ref_id', $refId)
            ->with('lines')
            ->first();
    }

    private function resolveDate(CarbonInterface|string|null $date): Carbon
    {
        if ($date instanceof CarbonInterface) {
            return Carbon::instance($date);
        }

        return $date === null ? Carbon::now() : Carbon::parse($date);
    }

    private function normalizeSource(JournalSourceEnum|string $source): JournalSourceEnum
    {
        return $source instanceof JournalSourceEnum ? $source : JournalSourceEnum::from($source);
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}

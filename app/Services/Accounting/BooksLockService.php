<?php

namespace App\Services\Accounting;

use App\Models\BooksLock;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * The accounting period lock.
 *
 * It guards only entries whose date the user chooses — manual journals and expenses.
 * Automatic entries for real events (a sale, a payment) are dated by the event itself
 * and are never blocked, otherwise the ledger would drift away from operations.
 */
class BooksLockService
{
    public function closedThrough(int $organizationId): ?Carbon
    {
        $date = BooksLock::query()
            ->where('organization_id', $organizationId)
            ->value('closed_through');

        return $date === null ? null : Carbon::parse($date);
    }

    /**
     * Whether a user-dated posting on this date is permitted.
     *
     * A null date means "now", which is always open — you cannot lock the future.
     */
    public function isOpen(int $organizationId, CarbonInterface|string|null $date): bool
    {
        if ($date === null) {
            return true;
        }

        $closedThrough = $this->closedThrough($organizationId);

        if ($closedThrough === null) {
            return true;
        }

        return Carbon::parse($date)->startOfDay()->gt($closedThrough->startOfDay());
    }

    /**
     * Refuse a user-dated posting that falls inside the locked period.
     */
    public function assertOpen(int $organizationId, CarbonInterface|string|null $date): void
    {
        if (! $this->isOpen($organizationId, $date)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.period_locked'));
        }
    }

    /**
     * Close the books through a date, or reopen them entirely with null.
     */
    public function setClosedThrough(int $organizationId, ?string $closedThrough, ?int $updatedById = null): BooksLock
    {
        $lock = BooksLock::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            ['closed_through' => $closedThrough, 'updated_by_id' => $updatedById]
        );

        return $lock->refresh();
    }
}

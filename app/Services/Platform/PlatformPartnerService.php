<?php

namespace App\Services\Platform;

use App\Models\PlatformPartner;
use App\Models\PlatformPartnerDistribution;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Founding partners: their stakes, what each has earned from the platform's profit, and
 * what has actually been paid out.
 */
class PlatformPartnerService
{
    private const CEILING_KEY = 'platform.ownershipCeiling';

    private const DEFAULT_CEILING = 100.0;

    public function __construct(
        private readonly PlatformConfigStore $config,
        private readonly PlatformBooks $books,
        private readonly AccountingReportService $reports,
    ) {}

    /**
     * Create or update a partner, holding total active ownership under the ceiling.
     *
     * The read and the write are serialized: without that, two concurrent creations each
     * see room for their own stake and both commit, putting the platform over 100%.
     * Reactivating a partner re-checks the ceiling for the same reason.
     *
     * @param  array<string, mixed>  $data
     */
    public function save(array $data, ?PlatformPartner $partner = null): PlatformPartner
    {
        return $this->serialized(function () use ($data, $partner) {
            $wouldBeActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : ($partner?->is_active ?? true);

            $percent = array_key_exists('ownership_percent', $data)
                ? (float) $data['ownership_percent']
                : (float) ($partner?->ownership_percent ?? 0);

            if ($wouldBeActive) {
                $this->assertWithinCeiling($percent, $partner?->getKey());
            }

            if ($partner === null) {
                // Refreshed, so the caller sees the database defaults (is_active) rather
                // than nulls for the columns the request did not mention.
                return PlatformPartner::query()->create($data)->refresh();
            }

            $partner->update($data);

            return $partner->refresh();
        });
    }

    /**
     * Every partner with what they have earned and what remains owed.
     *
     * @return array<string, mixed>
     */
    public function overview(): array
    {
        $netIncome = $this->platformNetIncome();
        $partners = PlatformPartner::query()->withSum('distributions as distributed', 'amount')->orderBy('name')->get();

        $rows = $partners->map(function (PlatformPartner $partner) use ($netIncome) {
            $share = round($partner->effectiveOwnership() / 100 * $netIncome, 2);
            $distributed = round((float) ($partner->distributed ?? 0), 2);

            return [
                'partner' => $partner,
                'share' => $share,
                'distributed' => $distributed,
                'net_owed' => round($share - $distributed, 2),
            ];
        });

        return [
            'net_income' => $netIncome,
            'ownership_ceiling' => $this->ceiling(),
            'allocated_ownership' => $this->activeOwnership(),
            'partners' => $rows,
        ];
    }

    /**
     * Record a payout. The row and its journal entry are one transaction — money leaving
     * with no entry would leave the books overstating the platform's cash.
     */
    public function distribute(PlatformPartner $partner, float $amount, ?string $date, ?string $note, ?int $adminId): PlatformPartnerDistribution
    {
        return DB::transaction(function () use ($partner, $amount, $date, $note, $adminId) {
            $distribution = PlatformPartnerDistribution::query()->create([
                'partner_id' => $partner->getKey(),
                'amount' => round($amount, 2),
                'date' => $date ?? Carbon::now()->toDateString(),
                'note' => $note,
                'recorded_by_id' => $adminId,
                'created_at' => Carbon::now(),
            ]);

            $this->books->postPartnerDistribution($distribution->load('partner'));

            return $distribution;
        });
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The platform's own net income, read from its books.
     */
    private function platformNetIncome(): float
    {
        $orgId = $this->books->organization()->getKey();

        return round((float) $this->reports->incomeStatement($orgId)['net_income'], 2);
    }

    private function assertWithinCeiling(float $percent, ?int $ignoreId): void
    {
        $ceiling = $this->ceiling();
        $allocated = $this->activeOwnership($ignoreId);

        abort_if(
            round($allocated + $percent, 2) > $ceiling,
            Response::HTTP_UNPROCESSABLE_ENTITY,
            __('api.partner_ownership_exceeded', ['ceiling' => $ceiling]),
        );
    }

    private function activeOwnership(?int $ignoreId = null): float
    {
        return round((float) PlatformPartner::query()
            ->active()
            ->when($ignoreId !== null, fn ($q) => $q->whereKeyNot($ignoreId))
            ->sum('ownership_percent'), 2);
    }

    private function ceiling(): float
    {
        return (float) ($this->config->get(self::CEILING_KEY) ?? self::DEFAULT_CEILING);
    }

    /**
     * Serialize the read-then-write around the ownership ceiling. A MySQL named lock in
     * production; a no-op on other drivers, where the test database is single-connection.
     */
    private function serialized(callable $callback): mixed
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return $callback();
        }

        DB::selectOne('SELECT GET_LOCK(?, 10) as acquired', ['platform-ownership']);

        try {
            return $callback();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) as released', ['platform-ownership']);
        }
    }
}

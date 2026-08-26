<?php

namespace App\Services\Accounting;

use App\Enum\Accounting\AssetAcquisitionSourceEnum;
use App\Enum\Accounting\AssetStatusEnum;
use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Models\Account;
use App\Models\AssetDepreciationEntry;
use App\Models\FixedAsset;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Manages fixed assets: acquisition, straight-line depreciation, and disposal.
 *
 * Every posting is idempotent. Depreciation in particular is guarded per (asset,
 * month): each month can only ever be charged once, so re-running the depreciation
 * sweep never double-charges.
 */
class AssetService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Register an asset and, unless funded by "none", post its acquisition entry.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): FixedAsset
    {
        $organizationId = $data['organization_id'];
        $paidFrom = $this->acquisitionSource($data['paid_from'] ?? AssetAcquisitionSourceEnum::None);

        return DB::transaction(function () use ($organizationId, $paidFrom, $data) {
            $asset = FixedAsset::query()->create([
                'organization_id' => $organizationId,
                'branch_id' => $data['branch_id'] ?? null,
                'name' => $data['name'],
                'category' => $data['category'],
                'cost' => round((float) $data['cost'], 2),
                'purchase_date' => $data['purchase_date'],
                'useful_life_months' => (int) $data['useful_life_months'],
                'salvage_value' => round((float) ($data['salvage_value'] ?? 0), 2),
                'status' => AssetStatusEnum::Active->value,
                'acquisition_paid_from' => $paidFrom->value,
                'note' => $data['note'] ?? null,
            ]);

            if ($paidFrom->postsAcquisition()) {
                $entry = $this->posting->post([
                    'organization_id' => $organizationId,
                    'source' => JournalSourceEnum::Manual,
                    'ref_type' => 'AssetAcquisition',
                    'ref_id' => $asset->getKey(),
                    'date' => $data['purchase_date'],
                    'memo' => __('api.asset_acquisition_memo', ['name' => $asset->name]),
                    'branch_id' => $asset->branch_id,
                    'lines' => [
                        ['account_id' => $this->account($organizationId, SystemAccountEnum::FixedAsset)->getKey(), 'debit' => $asset->cost],
                        ['account_id' => $this->account($organizationId, $paidFrom->systemAccount())->getKey(), 'credit' => $asset->cost],
                    ],
                ]);

                $asset->forceFill([
                    'acquisition_posted' => true,
                    'acquisition_journal_entry_id' => $entry->getKey(),
                ])->save();
            }

            return $asset->refresh();
        });
    }

    /**
     * Post depreciation for every whole month due since the last charge.
     *
     * The amount never exceeds the depreciable remainder, so an asset stops
     * depreciating once it reaches salvage value.
     */
    public function depreciate(FixedAsset $asset, ?Carbon $asOf = null): FixedAsset
    {
        $asOf ??= Carbon::now();
        $monthly = $asset->monthlyDepreciation();

        $lastDate = $asset->last_depreciation_date
            ? Carbon::parse($asset->last_depreciation_date)
            : Carbon::parse($asset->purchase_date);

        $months = $this->wholeMonthsBetween($lastDate, $asOf);

        for ($i = 0; $i < $months; $i++) {
            $remaining = $asset->depreciableRemaining();

            if ($remaining <= 0) {
                break;
            }

            $periodDate = $lastDate->copy()->addMonths($i + 1);
            $amount = round(min($monthly, $remaining), 2);

            if ($amount <= 0) {
                break;
            }

            $this->postMonth($asset, $periodDate, $amount);
        }

        // Advance the marker even when nothing was charged, so a fully depreciated
        // asset is not re-scanned every sweep.
        $asset->forceFill(['last_depreciation_date' => $asOf->toDateString()])->save();

        return $asset->refresh();
    }

    /**
     * Dispose of an asset: depreciate up to the disposal date, then post the disposal
     * with the resulting gain or loss on its own dedicated account.
     *
     * @param  array{proceeds?: float|int, via?: string, date?: string}  $data
     */
    public function dispose(FixedAsset $asset, array $data): FixedAsset
    {
        if ($asset->status === AssetStatusEnum::Disposed) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.asset_already_disposed'));
        }

        $disposalDate = isset($data['date']) ? Carbon::parse($data['date']) : Carbon::now();
        $proceeds = round((float) ($data['proceeds'] ?? 0), 2);
        $via = ($data['via'] ?? 'cash') === 'bank' ? SystemAccountEnum::Bank : SystemAccountEnum::Cash;

        // Catch depreciation up to the disposal date before computing book value.
        $this->depreciate($asset, $disposalDate);
        $asset->refresh();

        $accumulated = (float) $asset->accumulated_depreciation;
        $bookValue = $asset->bookValue();
        $gain = round($proceeds - $bookValue, 2);
        $organizationId = $asset->organization_id;

        return DB::transaction(function () use ($organizationId, $asset, $disposalDate, $proceeds, $via, $accumulated, $gain) {
            $lines = [];

            if ($proceeds > 0) {
                $lines[] = ['account_id' => $this->account($organizationId, $via)->getKey(), 'debit' => $proceeds];
            }
            if ($accumulated > 0) {
                $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::AccumulatedDepreciation)->getKey(), 'debit' => $accumulated];
            }
            $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::FixedAsset)->getKey(), 'credit' => (float) $asset->cost];

            if ($gain > 0) {
                $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::GainOnDisposal)->getKey(), 'credit' => $gain];
            } elseif ($gain < 0) {
                $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::LossOnDisposal)->getKey(), 'debit' => -$gain];
            }

            $entry = $this->posting->post([
                'organization_id' => $organizationId,
                'source' => JournalSourceEnum::Manual,
                'ref_type' => 'AssetDisposal',
                'ref_id' => $asset->getKey(),
                'date' => $disposalDate,
                'memo' => __('api.asset_disposal_memo', ['name' => $asset->name]),
                'branch_id' => $asset->branch_id,
                'lines' => $lines,
            ]);

            $asset->forceFill([
                'status' => AssetStatusEnum::Disposed->value,
                'disposed_date' => $disposalDate->toDateString(),
                'disposal_proceeds' => $proceeds,
                'disposal_gain' => $gain,
                'disposal_via' => $via === SystemAccountEnum::Bank ? 'bank' : 'cash',
                'disposal_journal_entry_id' => $entry->getKey(),
            ])->save();

            return $asset->refresh();
        });
    }

    /**
     * Delete an asset only when it carries no ledger footprint. Anything with a
     * posted acquisition or accrued depreciation must be disposed of, not deleted.
     */
    public function delete(FixedAsset $asset): void
    {
        if ($asset->acquisition_posted || (float) $asset->accumulated_depreciation > 0) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.asset_has_ledger_footprint'));
        }

        $asset->delete();
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function postMonth(FixedAsset $asset, Carbon $periodDate, float $amount): void
    {
        $period = $periodDate->format('Y-m');
        $organizationId = $asset->organization_id;

        DB::transaction(function () use ($asset, $organizationId, $period, $periodDate, $amount) {
            $entry = $this->posting->post([
                'organization_id' => $organizationId,
                'source' => JournalSourceEnum::Manual,
                'ref_type' => 'Depreciation',
                // The composite reference is the period guard at the ledger level.
                'ref_id' => "{$asset->getKey()}:{$period}",
                'date' => $periodDate->copy()->endOfMonth(),
                'memo' => __('api.asset_depreciation_memo', ['name' => $asset->name, 'period' => $period]),
                'branch_id' => $asset->branch_id,
                'lines' => [
                    ['account_id' => $this->account($organizationId, SystemAccountEnum::DepreciationExpense)->getKey(), 'debit' => $amount],
                    ['account_id' => $this->account($organizationId, SystemAccountEnum::AccumulatedDepreciation)->getKey(), 'credit' => $amount],
                ],
            ]);

            // The (asset, period) unique index is the second, authoritative guard: a
            // duplicate month is silently skipped rather than double-charged.
            AssetDepreciationEntry::query()->firstOrCreate(
                ['fixed_asset_id' => $asset->getKey(), 'period' => $period],
                [
                    'organization_id' => $organizationId,
                    'amount' => $amount,
                    'journal_entry_id' => $entry->getKey(),
                    'posted_at' => now(),
                ]
            );

            $asset->increment('accumulated_depreciation', $amount);
        });
    }

    private function wholeMonthsBetween(Carbon $from, Carbon $to): int
    {
        if ($to->lessThanOrEqualTo($from)) {
            return 0;
        }

        $months = ($to->year - $from->year) * 12 + ($to->month - $from->month);

        // A partial final month does not count until its day-of-month is reached.
        if ($to->day < $from->day) {
            $months--;
        }

        return max(0, $months);
    }

    private function account(int $organizationId, SystemAccountEnum $key): Account
    {
        return $this->chart->systemAccount($organizationId, $key);
    }

    private function acquisitionSource(AssetAcquisitionSourceEnum|string $source): AssetAcquisitionSourceEnum
    {
        return $source instanceof AssetAcquisitionSourceEnum ? $source : AssetAcquisitionSourceEnum::from($source);
    }
}

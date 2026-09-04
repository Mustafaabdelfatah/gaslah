<?php

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Resolves a reporting date range in the business timezone (Asia/Riyadh).
 *
 * Database timestamps are UTC; day boundaries are taken in Riyadh and converted back to
 * UTC for the query, so the first hours of each Riyadh day are not misfiled into the day
 * before. A range is clamped to at most 366 days so a caller cannot force years of orders
 * into one request.
 */
class ReportRangeService
{
    public const TIMEZONE = 'Asia/Riyadh';

    public const MAX_RANGE_DAYS = 366;

    private const DEFAULT_SPAN_DAYS = 29;

    /**
     * @return array{
     *     from_local: CarbonImmutable, to_inclusive_local: CarbonImmutable,
     *     from_utc: CarbonImmutable, to_exclusive_utc: CarbonImmutable,
     *     days: array<int, string>, period_days: int
     * }
     */
    public function resolve(?string $from, ?string $to): array
    {
        $now = CarbonImmutable::now(self::TIMEZONE);

        $toLocal = ($to ? CarbonImmutable::parse($to, self::TIMEZONE) : $now)->startOfDay();
        $fromLocal = ($from ? CarbonImmutable::parse($from, self::TIMEZONE) : $now->subDays(self::DEFAULT_SPAN_DAYS))->startOfDay();

        // Clamp the window to at most MAX_RANGE_DAYS.
        $earliest = $toLocal->subDays(self::MAX_RANGE_DAYS);
        if ($fromLocal->lessThan($earliest)) {
            $fromLocal = $earliest;
        }
        if ($fromLocal->greaterThan($toLocal)) {
            $fromLocal = $toLocal;
        }

        $toExclusiveLocal = $toLocal->addDay();

        return [
            'from_local' => $fromLocal,
            'to_inclusive_local' => $toLocal,
            'from_utc' => $fromLocal->utc(),
            'to_exclusive_utc' => $toExclusiveLocal->utc(),
            'days' => $this->dayKeys($fromLocal, $toLocal),
            'period_days' => (int) $fromLocal->diffInDays($toExclusiveLocal),
        ];
    }

    /**
     * The Riyadh day-key (Y-m-d) for a UTC timestamp.
     */
    public function dayKey(\DateTimeInterface $utc): string
    {
        return CarbonImmutable::instance($utc)->setTimezone(self::TIMEZONE)->format('Y-m-d');
    }

    /**
     * SQL expression that groups a UTC timestamp by its Riyadh calendar date.
     * Riyadh is UTC+3 year-round, so this does not depend on database timezone tables.
     */
    public function localDateExpression(string $column): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "date({$column}, '+3 hours')",
            'pgsql' => "DATE({$column} + INTERVAL '3 hours')",
            'sqlsrv' => "CAST(DATEADD(hour, 3, {$column}) AS date)",
            default => "DATE(DATE_ADD({$column}, INTERVAL 3 HOUR))",
        };
    }

    /**
     * Inclusive list of Y-m-d day keys in the local range.
     *
     * @return array<int, string>
     */
    private function dayKeys(CarbonImmutable $fromLocal, CarbonImmutable $toLocal): array
    {
        $days = [];
        for ($day = $fromLocal; $day->lessThanOrEqualTo($toLocal); $day = $day->addDay()) {
            $days[] = $day->format('Y-m-d');
        }

        return $days;
    }
}

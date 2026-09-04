<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Converts an inclusive business-calendar date filter to index-friendly UTC bounds.
 */
final class BusinessDateRange
{
    public static function todayStartUtc(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone())->startOfDay()->utc();
    }

    public static function startUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->utc();
    }

    public static function endExclusiveUtc(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, self::timezone())->startOfDay()->addDay()->utc();
    }

    /** Inclusive upper bound for SQL date/date-time columns without wrapping the column. */
    public static function dayAfter(string $date): string
    {
        return CarbonImmutable::parse($date)->addDay()->toDateString();
    }

    private static function timezone(): string
    {
        return (string) config('project.project.timezone', 'Asia/Riyadh');
    }
}

<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How a subscription coupon discounts: a percentage off, a fixed amount off, or free
 * months appended to the period (no price change).
 */
enum PlatformCouponTypeEnum: string
{
    use EnumMethods;

    case Percent = 'percent';
    case Fixed = 'fixed';
    case FreeMonths = 'free_months';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

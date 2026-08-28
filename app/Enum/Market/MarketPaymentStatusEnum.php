<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Whether a market order has been settled.
 */
enum MarketPaymentStatusEnum: string
{
    use EnumMethods;

    case Unpaid = 'unpaid';
    case Paid = 'paid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

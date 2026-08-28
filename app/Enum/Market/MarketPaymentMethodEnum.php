<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How a laundry settles a market order.
 */
enum MarketPaymentMethodEnum: string
{
    use EnumMethods;

    case Online = 'online';
    case Deferred = 'deferred';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

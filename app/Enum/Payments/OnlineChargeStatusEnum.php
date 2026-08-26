<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The lifecycle status of a gateway charge.
 */
enum OnlineChargeStatusEnum: string
{
    use EnumMethods;

    case Initiated = 'initiated';
    case Paid = 'paid';
    case Failed = 'failed';
    case Refunded = 'refunded';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

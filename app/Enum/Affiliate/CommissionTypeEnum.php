<?php

namespace App\Enum\Affiliate;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum CommissionTypeEnum: string
{
    use EnumMethods;

    case Percent = 'percent';
    case Fixed = 'fixed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

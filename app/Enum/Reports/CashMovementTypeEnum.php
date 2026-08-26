<?php

namespace App\Enum\Reports;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The direction of a cash-drawer movement within a shift.
 */
enum CashMovementTypeEnum: string
{
    use EnumMethods;

    case In = 'in';
    case Out = 'out';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

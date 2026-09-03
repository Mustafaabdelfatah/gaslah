<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Supported recurrence cadences for supplier bills and directly-paid expenses.
 */
enum RecurringFrequencyEnum: string
{
    use EnumMethods;

    case Weekly = 'weekly';
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

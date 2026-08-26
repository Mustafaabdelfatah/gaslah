<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Expense category, each mapped to the system account it posts against.
 */
enum ExpenseCategoryEnum: string
{
    use EnumMethods;

    case Opex = 'opex';
    case Payroll = 'payroll';
    case Rent = 'rent';
    case Utilities = 'utilities';
    case Supplies = 'supplies';

    /**
     * The system account this category's net amount is debited to.
     */
    public function systemAccount(): SystemAccountEnum
    {
        return match ($this) {
            self::Opex => SystemAccountEnum::OperatingExpenses,
            self::Payroll => SystemAccountEnum::Payroll,
            self::Rent => SystemAccountEnum::Rent,
            self::Utilities => SystemAccountEnum::Utilities,
            self::Supplies => SystemAccountEnum::Supplies,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

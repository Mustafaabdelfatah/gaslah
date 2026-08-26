<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How an expense is funded, which fixes the account credited for the gross amount.
 */
enum ExpensePaidFromEnum: string
{
    use EnumMethods;

    case Cash = 'cash';
    case Bank = 'bank';
    case AccountsPayable = 'ap';

    /**
     * The system account credited for the gross expense.
     */
    public function systemAccount(): SystemAccountEnum
    {
        return match ($this) {
            self::Cash => SystemAccountEnum::Cash,
            self::Bank => SystemAccountEnum::Bank,
            self::AccountsPayable => SystemAccountEnum::AccountsPayable,
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

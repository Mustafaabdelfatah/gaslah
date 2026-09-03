<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The liquid account used to settle an accrued supplier bill.
 */
enum PayableSettlementMethodEnum: string
{
    use EnumMethods;

    case Cash = 'cash';
    case Bank = 'bank';

    public function systemAccount(): SystemAccountEnum
    {
        return match ($this) {
            self::Cash => SystemAccountEnum::Cash,
            self::Bank => SystemAccountEnum::Bank,
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

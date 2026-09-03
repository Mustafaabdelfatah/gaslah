<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * A supplier bill remains open until a settlement entry pays it in full.
 */
enum PayableStatusEnum: string
{
    use EnumMethods;

    case Open = 'open';
    case Paid = 'paid';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

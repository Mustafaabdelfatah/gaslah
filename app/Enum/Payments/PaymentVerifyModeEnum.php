<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How a card or transfer payment was verified. Terminal means it went through a card
 * terminal and must carry the network approval reference.
 */
enum PaymentVerifyModeEnum: string
{
    use EnumMethods;

    case Manual = 'manual';
    case Terminal = 'terminal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enum\Messaging;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * WhatsApp Cloud message categories.
 */
enum WaCategoryEnum: string
{
    use EnumMethods;

    case Marketing = 'marketing';
    case Utility = 'utility';
    case Authentication = 'authentication';
    case Service = 'service';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enum\Orders;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum OrderPriorityEnum: string
{
    use EnumMethods;

    case Normal = 'normal';
    case Express = 'express';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

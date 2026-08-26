<?php

namespace App\Enum\Catalog;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum CustomerTypeEnum: string
{
    use EnumMethods;

    case Regular = 'regular';
    case Vip = 'vip';
    case Corporate = 'corporate';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

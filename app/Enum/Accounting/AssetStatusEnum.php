<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum AssetStatusEnum: string
{
    use EnumMethods;

    case Active = 'active';
    case Disposed = 'disposed';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

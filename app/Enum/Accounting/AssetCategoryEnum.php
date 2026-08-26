<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum AssetCategoryEnum: string
{
    use EnumMethods;

    case Equipment = 'equipment';
    case Vehicle = 'vehicle';
    case Furniture = 'furniture';
    case Computer = 'computer';
    case Other = 'other';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

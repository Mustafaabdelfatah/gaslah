<?php

namespace App\Enum\Catalog;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum PricingTypeEnum: string
{
    use EnumMethods;

    case PerPiece = 'per_piece';
    case PerWeight = 'per_weight';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

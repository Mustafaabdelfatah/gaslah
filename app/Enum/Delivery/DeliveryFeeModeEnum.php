<?php

namespace App\Enum\Delivery;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How self-delivery is priced: one flat fee for both directions, or a separate fee per
 * direction.
 */
enum DeliveryFeeModeEnum: string
{
    use EnumMethods;

    case Flat = 'flat';
    case PerDirection = 'per_direction';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

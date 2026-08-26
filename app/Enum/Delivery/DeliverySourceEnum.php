<?php

namespace App\Enum\Delivery;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Who created a delivery request: a staff member or the customer via the portal.
 */
enum DeliverySourceEnum: string
{
    use EnumMethods;

    case Staff = 'staff';
    case Portal = 'portal';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

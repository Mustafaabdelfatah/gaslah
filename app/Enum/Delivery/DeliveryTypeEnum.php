<?php

namespace App\Enum\Delivery;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The direction of a delivery trip.
 *
 * A row is always one of Pickup or Delivery. The creation input additionally accepts
 * Both, which is expanded immediately into two separate rows and never stored.
 */
enum DeliveryTypeEnum: string
{
    use EnumMethods;

    case Pickup = 'pickup';
    case Delivery = 'delivery';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

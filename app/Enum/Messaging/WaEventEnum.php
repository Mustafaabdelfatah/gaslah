<?php

namespace App\Enum\Messaging;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Automatic and manual messaging events, and their default category.
 */
enum WaEventEnum: string
{
    use EnumMethods;

    case OrderCreated = 'order_created';
    case OrderReady = 'order_ready';
    case OrderCompleted = 'order_completed';
    case Otp = 'otp';
    case Invoice = 'invoice';
    case Delivery = 'delivery';
    case Manual = 'manual';
    case Test = 'test';

    public function category(): WaCategoryEnum
    {
        return match ($this) {
            self::Otp => WaCategoryEnum::Authentication,
            self::Manual => WaCategoryEnum::Marketing,
            self::Test => WaCategoryEnum::Service,
            default => WaCategoryEnum::Utility,
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

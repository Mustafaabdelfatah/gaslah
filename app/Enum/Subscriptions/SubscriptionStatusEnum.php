<?php

namespace App\Enum\Subscriptions;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Lifecycle state of a customer subscription.
 *
 * Only an Active subscription within its period may be consumed at the point of sale.
 */
enum SubscriptionStatusEnum: string
{
    use EnumMethods;

    case Active = 'active';
    case Frozen = 'frozen';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

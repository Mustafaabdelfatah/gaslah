<?php

namespace App\Enum\Subscriptions;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The billing cadence of a subscription plan.
 *
 * The cycle is the sole source of a subscription's period length: a purchase runs from
 * now until now plus this many months.
 */
enum SubscriptionCycleEnum: string
{
    use EnumMethods;

    case Monthly = 'monthly';
    case Quarterly = 'quarterly';
    case Yearly = 'yearly';

    /**
     * Period length in months.
     */
    public function months(): int
    {
        return match ($this) {
            self::Monthly => 1,
            self::Quarterly => 3,
            self::Yearly => 12,
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

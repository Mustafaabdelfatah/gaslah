<?php

namespace App\Enum\Subscriptions;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How a plan's balance is initialised at purchase and drawn down at consumption.
 *
 * - PieceQuota: a count of garments, one drawn per piece washed.
 * - PrepaidBalance: a money balance, the order remainder drawn from it.
 * - UnlimitedService: no counter, the period validity alone is enough.
 */
enum SubscriptionTypeEnum: string
{
    use EnumMethods;

    case PieceQuota = 'piece_quota';
    case PrepaidBalance = 'prepaid_balance';
    case UnlimitedService = 'unlimited_service';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

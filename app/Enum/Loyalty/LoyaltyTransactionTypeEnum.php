<?php

namespace App\Enum\Loyalty;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The kind of loyalty points movement.
 *
 * Earn and Expire are defined for completeness but unused: there is no automatic
 * earning on orders and no expiry job. A manual positive adjustment writes Bonus, a
 * manual negative one or a wallet redemption writes Redeem.
 */
enum LoyaltyTransactionTypeEnum: string
{
    use EnumMethods;

    case Earn = 'earn';
    case Redeem = 'redeem';
    case Expire = 'expire';
    case Bonus = 'bonus';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

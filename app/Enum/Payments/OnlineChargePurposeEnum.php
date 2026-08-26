<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * What a gateway charge was for.
 *
 * OrderPayment is created in the payments domain; Subscription (a platform subscription
 * charge) is created in the subscriptions/platform domain and only read in the sales log.
 */
enum OnlineChargePurposeEnum: string
{
    use EnumMethods;

    case OrderPayment = 'order_payment';
    case Subscription = 'subscription';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enum\Global;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * What an OTP code authorises.
 *
 * The purpose is a security boundary: a code minted to approve a till payment
 * (PosWallet) must never be usable as a 30-day portal session code, so the two are
 * kept apart even for the same phone.
 */
enum OtpPurposeEnum: string
{
    use EnumMethods;

    case PortalLogin = 'portal_login';
    case PosWallet = 'pos_wallet';
    case DriverLogin = 'driver_login';
    case AffiliateLogin = 'affiliate_login';
    case OrderPayment = 'order_payment';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enum\Global;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The authentication surface a token belongs to.
 *
 * Surfaces are kept apart by giving each its own Sanctum guard and authenticatable
 * model, so a token minted for one surface can never authenticate on another. This
 * enum records the surface on revocation rows for auditing.
 */
enum TokenKindEnum: string
{
    use EnumMethods;

    case Staff = 'staff';
    case Platform = 'platform';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Driver = 'driver';
    case Affiliate = 'affiliate';
    case PosOtp = 'pos_otp';
}

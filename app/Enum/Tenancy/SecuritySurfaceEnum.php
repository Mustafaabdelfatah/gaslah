<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Login surface an authentication attempt was made against.
 *
 * Lockout counters are scoped per surface so a locked staff login never blocks the
 * separate platform console for the same address.
 */
enum SecuritySurfaceEnum: string
{
    use EnumMethods;

    case Staff = 'staff';
    case Admin = 'admin';
    case Customer = 'customer';
    case Supplier = 'supplier';
    case Driver = 'driver';
    case Affiliate = 'affiliate';
}

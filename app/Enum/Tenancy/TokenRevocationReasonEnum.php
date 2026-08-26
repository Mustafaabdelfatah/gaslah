<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Why a token identifier was placed on the denylist.
 *
 * `Reserve` is the money-critical one: it records a one-shot consent proof that was
 * burned atomically before any balance moved.
 */
enum TokenRevocationReasonEnum: string
{
    use EnumMethods;

    case Logout = 'logout';
    case Forced = 'forced';
    case Reserve = 'reserve';
    case ImpersonationStop = 'impersonation_stop';
}

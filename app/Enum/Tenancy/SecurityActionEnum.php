<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Authentication outcome recorded on every login attempt.
 *
 * A successful attempt clears the lockout window, so failures older than the last
 * success are never counted against the caller.
 */
enum SecurityActionEnum: string
{
    use EnumMethods;

    case LoginOk = 'login_ok';
    case LoginFailed = 'login_failed';
}

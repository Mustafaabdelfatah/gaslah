<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Subscription lifecycle events (feed the MRR waterfall and churn/new-MRR classification).
 */
enum PlatformEventTypeEnum: string
{
    use EnumMethods;

    case Signup = 'signup';
    case TrialStart = 'trial_start';
    case TrialConvert = 'trial_convert';
    case Renew = 'renew';
    case PlanChange = 'plan_change';
    case Extend = 'extend';
    case CancelScheduled = 'cancel_scheduled';
    case Reactivate = 'reactivate';
    case Suspend = 'suspend';
    case Expire = 'expire';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

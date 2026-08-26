<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Actions a platform admin can take against a tenant that must leave an audit trail.
 */
enum PlatformAuditActionEnum: string
{
    use EnumMethods;

    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
    case UpdateEntitlements = 'update_entitlements';
    case UpdateSubscription = 'update_subscription';
    case StartTrial = 'start_trial';
    case Extend = 'extend';
    case Impersonate = 'impersonate';
    case Archive = 'archive';
    case Unarchive = 'unarchive';
    case Export = 'export';
    case Dunning = 'dunning';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

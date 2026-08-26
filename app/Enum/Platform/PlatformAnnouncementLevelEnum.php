<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Severity of a platform → tenant broadcast banner.
 */
enum PlatformAnnouncementLevelEnum: string
{
    use EnumMethods;

    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

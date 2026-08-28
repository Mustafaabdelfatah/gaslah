<?php

namespace App\Enum\Support;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How urgently a support ticket needs answering.
 */
enum SupportPriorityEnum: string
{
    use EnumMethods;

    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

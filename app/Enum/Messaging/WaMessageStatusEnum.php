<?php

namespace App\Enum\Messaging;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The delivery state of a WhatsApp/SMS message attempt.
 *
 * QUEUED..READ count toward the monthly quota (a queued row is charged immediately, so
 * check-and-insert must be serialized); BLOCKED (commercial gate) and FAILED (provider
 * failure) are terminal and do not consume quota.
 */
enum WaMessageStatusEnum: string
{
    use EnumMethods;

    case Queued = 'queued';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Read = 'read';
    case Failed = 'failed';
    case Blocked = 'blocked';

    /**
     * @return array<int, string>
     */
    public static function countedValues(): array
    {
        return [self::Queued->value, self::Sent->value, self::Delivered->value, self::Read->value];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

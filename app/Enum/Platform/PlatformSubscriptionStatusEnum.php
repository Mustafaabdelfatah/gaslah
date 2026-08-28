<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Stored status of an organization's platform subscription. EXPIRED is derived (an
 * active/trial subscription past its period end), never stored.
 */
enum PlatformSubscriptionStatusEnum: string
{
    use EnumMethods;

    case Trial = 'trial';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';

    /**
     * Whether this stored status permits writes (before the period-end check).
     */
    public function isWritable(): bool
    {
        return $this === self::Trial || $this === self::Active;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * The statuses that still permit writes, for queries that need the set rather than
     * one case at a time.
     *
     * @return array<int, string>
     */
    public static function writableValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isWritable()),
        ));
    }
}

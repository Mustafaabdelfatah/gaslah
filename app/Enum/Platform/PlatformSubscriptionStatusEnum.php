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
}

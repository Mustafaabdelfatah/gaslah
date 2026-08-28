<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Where a market supplier stands with the platform.
 */
enum MarketSupplierStatusEnum: string
{
    use EnumMethods;

    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Suspended = 'suspended';

    /**
     * Only an approved supplier's products are offered to buyers. Everything else — under
     * review, suspended, rejected — is invisible in the market.
     */
    public function isSellable(): bool
    {
        return $this === self::Approved;
    }

    /**
     * A rejected supplier cannot sign in at all. Pending and suspended ones can, so the
     * portal can tell them why they are not selling.
     */
    public function canSignIn(): bool
    {
        return $this !== self::Rejected;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

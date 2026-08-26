<?php

namespace App\Enum\Affiliate;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum AffiliateReferralStatusEnum: string
{
    use EnumMethods;

    case Pending = 'pending';
    case Approved = 'approved';
    case Paid = 'paid';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

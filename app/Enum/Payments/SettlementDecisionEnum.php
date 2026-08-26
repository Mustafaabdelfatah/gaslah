<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * An admin's vote on a payout settlement.
 */
enum SettlementDecisionEnum: string
{
    use EnumMethods;

    case Approve = 'approve';
    case Reject = 'reject';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

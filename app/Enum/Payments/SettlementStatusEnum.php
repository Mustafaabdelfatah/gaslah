<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The state of a bank payout settlement (maker-checker).
 */
enum SettlementStatusEnum: string
{
    use EnumMethods;

    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /**
     * The two states in which the settlement holds its payments (an open settlement).
     *
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return [self::PendingApproval->value, self::Approved->value];
    }

    public function isOpen(): bool
    {
        return in_array($this->value, self::openValues(), true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

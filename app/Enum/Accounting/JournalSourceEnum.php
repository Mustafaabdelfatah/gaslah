<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Origin of a journal entry.
 *
 * Deliberately closed to these seven values. New money flows (subscriptions, loyalty,
 * assets, payables…) are distinguished by ref_type rather than by adding a source, so
 * the set stays aligned with the reporting filters and never needs widening.
 */
enum JournalSourceEnum: string
{
    use EnumMethods;

    case Manual = 'manual';
    case Order = 'order';
    case Payment = 'payment';
    case Refund = 'refund';
    case Expense = 'expense';
    case WalletTopup = 'wallet_topup';
    case Opening = 'opening';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

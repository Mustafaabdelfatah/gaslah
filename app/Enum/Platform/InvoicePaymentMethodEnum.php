<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How the platform expects (or received) payment for a subscription or device invoice.
 */
enum InvoicePaymentMethodEnum: string
{
    use EnumMethods;

    case Cash = 'cash';
    case BankTransfer = 'bank_transfer';
    case Gateway = 'gateway';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

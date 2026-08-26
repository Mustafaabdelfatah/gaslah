<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Direction of a wallet movement. The amount is always positive; the type decides
 * whether it adds to or draws from the balance.
 */
enum WalletTransactionTypeEnum: string
{
    use EnumMethods;

    case Topup = 'topup';
    case Debit = 'debit';
    case Refund = 'refund';

    /**
     * Whether the movement increases the balance.
     */
    public function isCredit(): bool
    {
        return $this === self::Topup || $this === self::Refund;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

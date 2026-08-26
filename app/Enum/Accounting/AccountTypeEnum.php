<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Classification of a chart-of-accounts entry, which fixes its natural balance side.
 */
enum AccountTypeEnum: string
{
    use EnumMethods;

    case Asset = 'asset';
    case Liability = 'liability';
    case Equity = 'equity';
    case Revenue = 'revenue';
    case Expense = 'expense';

    /**
     * Assets and expenses carry a debit balance; everything else a credit balance.
     * This is what decides the sign a report gives an account's net movement.
     */
    public function isDebitNormal(): bool
    {
        return $this === self::Asset || $this === self::Expense;
    }

    /**
     * Signed balance for this account type given its debit and credit totals.
     */
    public function balance(float $debit, float $credit): float
    {
        return $this->isDebitNormal()
            ? round($debit - $credit, 2)
            : round($credit - $debit, 2);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

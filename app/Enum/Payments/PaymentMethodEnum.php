<?php

namespace App\Enum\Payments;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Payment methods that create a payment row.
 *
 * Subscription is deliberately absent: paying from a subscription creates no payment
 * row (it is recognised in accounting by ref type instead), and deferred is a status
 * marker that collects nothing.
 */
enum PaymentMethodEnum: string
{
    use EnumMethods;

    case Cash = 'cash';
    case Card = 'card';
    case Transfer = 'transfer';
    case Wallet = 'wallet';
    case Deferred = 'deferred';

    /**
     * Whether the customer must be present and prove consent with an OTP.
     */
    public function requiresCustomerConsent(): bool
    {
        return $this === self::Wallet;
    }

    /**
     * Card and transfer are collected outside the app and must be confirmed.
     */
    public function requiresVerification(): bool
    {
        return $this === self::Card || $this === self::Transfer;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

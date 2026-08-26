<?php

namespace App\Enum\Orders;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum PaymentStatusEnum: string
{
    use EnumMethods;

    case Unpaid = 'unpaid';
    case Partial = 'partial';
    case Paid = 'paid';
    case Deferred = 'deferred';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

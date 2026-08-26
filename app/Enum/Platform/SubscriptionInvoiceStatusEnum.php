<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Lifecycle of a platform tax invoice: a freely-deletable draft, or an issued ZATCA
 * document that has consumed an ICV/PIH slot and posted revenue (immutable).
 */
enum SubscriptionInvoiceStatusEnum: string
{
    use EnumMethods;

    case Draft = 'draft';
    case Issued = 'issued';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

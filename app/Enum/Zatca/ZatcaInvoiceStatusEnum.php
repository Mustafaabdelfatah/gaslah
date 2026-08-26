<?php

namespace App\Enum\Zatca;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Lifecycle state of a stored ZATCA (Phase 2) invoice.
 *
 * Generated on creation; Reported once the tax authority accepts it. There is no reverse
 * transition.
 */
enum ZatcaInvoiceStatusEnum: string
{
    use EnumMethods;

    case Generated = 'generated';
    case Reported = 'reported';

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

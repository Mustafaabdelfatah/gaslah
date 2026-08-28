<?php

namespace App\Enum\Crm;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Where a prospective laundry sits in the sales pipeline.
 */
enum LeadStageEnum: string
{
    use EnumMethods;

    case New = 'new';
    case Contacted = 'contacted';
    case Qualified = 'qualified';
    case Won = 'won';
    case Lost = 'lost';

    /**
     * Stages that are still being worked. Won and lost are the two ends of the pipeline,
     * and neither counts towards its value.
     */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Won, self::Lost], true);
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * @return array<int, string>
     */
    public static function openValues(): array
    {
        return array_values(array_map(
            static fn (self $case): string => $case->value,
            array_filter(self::cases(), static fn (self $case): bool => $case->isOpen()),
        ));
    }
}

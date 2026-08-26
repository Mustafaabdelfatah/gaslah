<?php

namespace App\Enum\Catalog;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The three laundry service types a product can be priced for.
 *
 * The order is fixed — it drives the POS button layout — and any other value is
 * ignored rather than stored.
 */
enum ServiceTypeEnum: string
{
    use EnumMethods;

    case WashIron = 'wash_iron';
    case Iron = 'iron';
    case Wash = 'wash';

    /**
     * Display order for POS, slowest service first.
     *
     * @return array<int, self>
     */
    public static function ordered(): array
    {
        return [self::WashIron, self::Iron, self::Wash];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

<?php

namespace App\Enum\Tenancy;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * Grouping of the entitlement feature catalogue.
 *
 * Core features are never gated: they make up the base product and stay enabled even
 * when a subscription lapses.
 */
enum FeatureCategoryEnum: string
{
    use EnumMethods;

    case Core = 'core';
    case Operations = 'operations';
    case Growth = 'growth';
    case Finance = 'finance';
}

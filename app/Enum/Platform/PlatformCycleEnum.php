<?php

namespace App\Enum\Platform;

use HasanHawary\LookupManager\Trait\EnumMethods;

enum PlatformCycleEnum: string
{
    use EnumMethods;

    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function months(): int
    {
        return $this === self::Yearly ? 12 : 1;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

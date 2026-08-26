<?php

namespace App\Enum\Accounting;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How a fixed asset's acquisition was funded.
 *
 * `None` records the asset without an acquisition entry — used when the asset is
 * already on the books and only needs tracking for depreciation.
 */
enum AssetAcquisitionSourceEnum: string
{
    use EnumMethods;

    case Cash = 'cash';
    case Bank = 'bank';
    case AccountsPayable = 'ap';
    case None = 'none';

    public function systemAccount(): ?SystemAccountEnum
    {
        return match ($this) {
            self::Cash => SystemAccountEnum::Cash,
            self::Bank => SystemAccountEnum::Bank,
            self::AccountsPayable => SystemAccountEnum::AccountsPayable,
            self::None => null,
        };
    }

    public function postsAcquisition(): bool
    {
        return $this !== self::None;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

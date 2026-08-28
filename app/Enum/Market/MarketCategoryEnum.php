<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The fixed product categories of the supplies market.
 *
 * One source: the legacy system repeated this list verbatim in the buyer controller and
 * the supplier controller, so a category added to one browsed differently from the other.
 *
 * It is also enforced, not merely displayed. The legacy validation took any string up to
 * 60 characters, which let a typo create a category nothing could ever browse to.
 */
enum MarketCategoryEnum: string
{
    use EnumMethods;

    case Detergents = 'detergents';
    case Chemicals = 'chemicals';
    case Hangers = 'hangers';
    case Bags = 'bags';
    case Packaging = 'packaging';
    case Machines = 'machines';
    case SpareParts = 'spare_parts';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Detergents => 'منظّفات ومساحيق',
            self::Chemicals => 'مواد كيميائية',
            self::Hangers => 'علّاقات وشمّاعات',
            self::Bags => 'أكياس وتغليف',
            self::Packaging => 'مواد تعبئة',
            self::Machines => 'معدّات وآلات',
            self::SpareParts => 'قطع غيار',
            self::Other => 'أخرى',
        };
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }

    /**
     * The catalogue as the browse screens render it.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public static function catalogue(): array
    {
        return array_map(
            static fn (self $case): array => ['key' => $case->value, 'label' => $case->label()],
            self::cases(),
        );
    }
}

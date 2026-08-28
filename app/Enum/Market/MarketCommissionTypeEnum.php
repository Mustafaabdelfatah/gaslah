<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * How the platform takes its cut of a market order.
 *
 * The commission comes out of the supplier's side only — the buyer always pays the
 * subtotal, so a change to commission never moves the price a laundry sees.
 */
enum MarketCommissionTypeEnum: string
{
    use EnumMethods;

    case Percent = 'percent';
    case Fixed = 'fixed';
    case Subscription = 'subscription';

    /**
     * The platform's cut when a supplier has no rate of their own.
     */
    public const DEFAULT_PERCENT = 8.0;

    /**
     * What the platform keeps from an order of this size.
     *
     * A fixed commission is capped at the subtotal: a small order must never leave the
     * supplier owing money to have sold something.
     */
    public function on(float $subtotal, float $value): float
    {
        return round(match ($this) {
            self::Percent => $subtotal * $value / 100,
            self::Fixed => min(max($value, 0), $subtotal),
            self::Subscription => 0,
        }, 2);
    }

    /**
     * The rate to record on the order — meaningful for a percentage, zero otherwise.
     */
    public function rateFor(float $value): float
    {
        return $this === self::Percent ? round($value, 2) : 0.0;
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

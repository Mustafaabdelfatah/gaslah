<?php

namespace App\Enum\Market;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * A market order's fulfilment state, and the only moves allowed between them.
 *
 * The machine lives on the enum rather than in the controller so every caller is held to
 * the same graph — an order cannot be shipped before it is confirmed, and neither
 * delivered nor cancelled can be walked back.
 */
enum MarketOrderStatusEnum: string
{
    use EnumMethods;

    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::Cancelled],
            self::Confirmed => [self::Shipped, self::Cancelled],
            self::Shipped => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    public function isFinal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * States a supplier may move an order into. Pending is excluded: it is where an order
     * starts, never somewhere it returns to.
     *
     * @return array<int, string>
     */
    public static function targetValues(): array
    {
        return array_values(array_diff(self::values(), [self::Pending->value]));
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}

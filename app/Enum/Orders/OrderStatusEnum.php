<?php

namespace App\Enum\Orders;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * An order's workflow state.
 *
 * The machine is strictly one-directional: an order cannot be cancelled once it is
 * ready, and Delivered and Cancelled are terminal.
 */
enum OrderStatusEnum: string
{
    use EnumMethods;

    case Received = 'received';
    case Processing = 'processing';
    case Ready = 'ready';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * The states this one may transition to.
     *
     * @return array<int, self>
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Received => [self::Processing, self::Cancelled],
            self::Processing => [self::Ready, self::Cancelled],
            self::Ready => [self::Delivered],
            self::Delivered, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedNext(), true);
    }

    public function isTerminal(): bool
    {
        return $this->allowedNext() === [];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

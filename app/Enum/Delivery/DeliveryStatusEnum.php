<?php

namespace App\Enum\Delivery;

use HasanHawary\LookupManager\Trait\EnumMethods;

/**
 * The state of a delivery trip.
 *
 * The seven states are shared, but the allowed transitions differ by trip type. This
 * enum is the single source of truth for the flow — the pickup path ends at AtFacility,
 * the delivery path at Delivered, and Cancelled is reachable from any non-terminal
 * state.
 */
enum DeliveryStatusEnum: string
{
    use EnumMethods;

    case Requested = 'requested';
    case Assigned = 'assigned';
    case PickedUp = 'picked_up';
    case AtFacility = 'at_facility';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';

    /**
     * The states this one may transition to, for the given trip type.
     *
     * @return array<int, self>
     */
    public function allowedNext(DeliveryTypeEnum $type): array
    {
        return match ($type) {
            DeliveryTypeEnum::Pickup => match ($this) {
                self::Requested => [self::Assigned, self::Cancelled],
                self::Assigned => [self::PickedUp, self::Cancelled],
                self::PickedUp => [self::AtFacility, self::Cancelled],
                default => [],
            },
            DeliveryTypeEnum::Delivery => match ($this) {
                self::Requested => [self::Assigned, self::Cancelled],
                self::Assigned => [self::PickedUp, self::Cancelled],
                self::PickedUp => [self::OutForDelivery, self::Cancelled],
                self::OutForDelivery => [self::Delivered, self::Cancelled],
                default => [],
            },
        };
    }

    public function canTransitionTo(self $target, DeliveryTypeEnum $type): bool
    {
        return in_array($target, $this->allowedNext($type), true);
    }

    public function isTerminal(DeliveryTypeEnum $type): bool
    {
        return $this->allowedNext($type) === [];
    }

    /**
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}

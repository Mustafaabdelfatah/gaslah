<?php

namespace App\Services\Delivery;

use App\Enum\Delivery\DeliveryFeeModeEnum;
use App\Enum\Delivery\DeliveryTypeEnum;
use App\Models\DeliveryRequest;
use App\Models\DeliverySetting;
use App\Models\DeliveryZone;

/**
 * Reads and writes an organization's delivery configuration, and computes a trip's fee.
 *
 * Stored settings are merged over the defaults, so a partial save never loses a key. An
 * organization can only enable a method the platform has made available — a requested
 * method that is not available is forced off rather than rejected, so a save always
 * settles on a valid state.
 */
class DeliverySettingsService
{
    /**
     * The full default configuration.
     *
     * @return array<string, mixed>
     */
    public function defaults(): array
    {
        return [
            'methods' => [
                'selfDelivery' => true,
                'platformDriver' => false,
                'integration' => false,
            ],
            'self' => [
                'feeMode' => DeliveryFeeModeEnum::Flat->value,
                'flatFee' => 0,
                'pickupFee' => 0,
                'deliveryFee' => 0,
                'hoursFrom' => '09:00',
                'hoursTo' => '21:00',
                'slotMinutes' => 60,
            ],
            'workflow' => [
                'portalOrdering' => true,
                'manualAssign' => false,
                'requireAcceptance' => true,
                'showMap' => true,
                'photoProof' => false,
                'basketInventory' => true,
                'invoiceApproval' => false,
                'notifyWhatsapp' => true,
                'notifySms' => false,
            ],
        ];
    }

    /**
     * The methods the platform has made available to the organization (read-only to it).
     *
     * @return array<string, bool>
     */
    public function availableMethods(int $organizationId): array
    {
        $defaults = ['selfDelivery' => true, 'platformDriver' => true, 'integration' => false];
        $stored = $this->row($organizationId)?->available_methods;

        return array_replace($defaults, is_array($stored) ? $stored : []);
    }

    /**
     * The organization's effective settings (defaults merged with stored) plus the
     * read-only `available` block.
     *
     * @return array<string, mixed>
     */
    public function resolve(int $organizationId): array
    {
        $stored = $this->row($organizationId)?->settings;
        $settings = array_replace_recursive($this->defaults(), is_array($stored) ? $stored : []);
        $settings['available'] = $this->availableMethods($organizationId);

        return $settings;
    }

    /**
     * Persist an organization's settings, forcing any not-available method off.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(int $organizationId, array $input): array
    {
        $current = $this->resolve($organizationId);
        unset($current['available']);

        $merged = array_replace_recursive($current, $input);
        $available = $this->availableMethods($organizationId);

        foreach ($merged['methods'] as $method => $enabled) {
            $merged['methods'][$method] = (bool) $enabled && ($available[$method] ?? false);
        }

        DeliverySetting::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            ['settings' => $merged],
        );

        return $this->resolve($organizationId);
    }

    /**
     * Platform owner sets which methods the organization may use.
     *
     * @param  array<string, bool>  $methods
     * @return array<string, bool>
     */
    public function setAvailableMethods(int $organizationId, array $methods): array
    {
        DeliverySetting::query()->updateOrCreate(
            ['organization_id' => $organizationId],
            ['available_methods' => array_replace($this->availableMethods($organizationId), $methods)],
        );

        return $this->availableMethods($organizationId);
    }

    /**
     * Whether a delivery method is enabled in the given settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function methodEnabled(array $settings, string $method): bool
    {
        return (bool) ($settings['methods'][$method] ?? false);
    }

    /**
     * The fee for one trip: a chosen zone's fee overrides everything, otherwise the
     * self-delivery pricing (flat, or per direction).
     *
     * @param  array<string, mixed>  $settings
     */
    public function feeFor(array $settings, DeliveryTypeEnum $type, ?DeliveryZone $zone): float
    {
        if ($zone !== null) {
            return round((float) $zone->fee, 2);
        }

        $self = $settings['self'] ?? [];

        if (($self['feeMode'] ?? DeliveryFeeModeEnum::Flat->value) === DeliveryFeeModeEnum::PerDirection->value) {
            $fee = $type === DeliveryTypeEnum::Pickup ? ($self['pickupFee'] ?? 0) : ($self['deliveryFee'] ?? 0);

            return round((float) $fee, 2);
        }

        return round((float) ($self['flatFee'] ?? 0), 2);
    }

    /**
     * Whether the request needs customer invoice approval, per workflow settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function workflow(array $settings, string $key): bool
    {
        return (bool) ($settings['workflow'][$key] ?? false);
    }

    /**
     * Open request count per driver — used to balance auto-assignment.
     *
     * @param  array<int, int>  $driverIds
     * @return array<int, int>
     */
    public function openLoads(array $driverIds): array
    {
        if ($driverIds === []) {
            return [];
        }

        return DeliveryRequest::query()
            ->whereIn('driver_id', $driverIds)
            ->whereIn('status', ['assigned', 'picked_up', 'out_for_delivery'])
            ->selectRaw('driver_id, COUNT(*) as open_count')
            ->groupBy('driver_id')
            ->pluck('open_count', 'driver_id')
            ->all();
    }

    private function row(int $organizationId): ?DeliverySetting
    {
        return DeliverySetting::query()->where('organization_id', $organizationId)->first();
    }
}

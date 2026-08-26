<?php

namespace App\Http\Controllers\API\Tenancy\Delivery;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Delivery\DeliveryZoneRequest;
use App\Http\Requests\Delivery\DriverRequest;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\DeliverySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeliveryController extends TenantController
{
    private const FEATURE = 'delivery';

    public function __construct(
        private readonly DeliverySettingsService $settings,
        private readonly DeliveryService $delivery,
    ) {
        parent::__construct();
    }

    /*
    |--------------------------------------------------------------------------
    | Settings
    |--------------------------------------------------------------------------
    */
    public function settings(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        return successResponse($this->settings->resolve($this->organizationId()));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        // Only the general manager may change delivery configuration.
        $this->requireSuperAdmin();
        $this->requireFeature(self::FEATURE);

        $data = $request->validate([
            'methods' => ['nullable', 'array'],
            'methods.selfDelivery' => ['nullable', 'boolean'],
            'methods.platformDriver' => ['nullable', 'boolean'],
            'methods.integration' => ['nullable', 'boolean'],
            'self' => ['nullable', 'array'],
            'self.feeMode' => ['nullable', 'in:flat,per_direction'],
            'self.flatFee' => ['nullable', 'numeric', 'min:0'],
            'self.pickupFee' => ['nullable', 'numeric', 'min:0'],
            'self.deliveryFee' => ['nullable', 'numeric', 'min:0'],
            'self.hoursFrom' => ['nullable', 'date_format:H:i'],
            'self.hoursTo' => ['nullable', 'date_format:H:i'],
            'self.slotMinutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'workflow' => ['nullable', 'array'],
        ]);

        return successResponse($this->settings->save($this->organizationId(), $data), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Zones
    |--------------------------------------------------------------------------
    */
    public function zones(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $zones = DeliveryZone::query()
            ->forOrganization($this->organizationId())
            ->inBranches($this->readBranchIds())
            ->orderBy('sort_order')
            ->get();

        return successResponse($zones);
    }

    public function storeZone(DeliveryZoneRequest $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $branchId = $this->writeBranchId();
        $sortOrder = DeliveryZone::query()->where('branch_id', $branchId)->count();

        $zone = DeliveryZone::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $branchId,
            'sort_order' => $sortOrder,
        ]);

        return successResponse($zone, __('api.created_success'), 201);
    }

    public function updateZone(DeliveryZoneRequest $request, DeliveryZone $zone): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        abort_unless($zone->organization_id === $this->organizationId(), 404, __('api.record_not_found'));

        $zone->update($request->validated());

        return successResponse($zone->refresh(), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    public function drivers(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $settings = $this->settings->resolve($this->organizationId());
        $drivers = $this->delivery->assignableDrivers($this->readBranchIds(), $settings)
            ->orderBy('name')
            ->get();

        return successResponse($drivers);
    }

    public function storeDriver(DriverRequest $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertPhoneIsFree($request->input('phone'));

        $driver = Driver::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
            'is_platform' => false,
        ]);

        return successResponse($driver, __('api.created_success'), 201);
    }

    public function updateDriver(DriverRequest $request, Driver $driver): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwnedDriver($driver);
        $this->assertPhoneIsFree($request->input('phone'), $driver->getKey());

        $driver->update($request->validated());

        return successResponse($driver->refresh(), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function assertOwnedDriver(Driver $driver): void
    {
        // Staff manage their own drivers only; platform drivers belong to the platform.
        abort_if($driver->is_platform || $driver->organization_id !== $this->organizationId(), 404, __('api.record_not_found'));
    }

    private function assertPhoneIsFree(?string $phone, ?int $ignoreId = null): void
    {
        if ($phone === null) {
            return;
        }

        // A driver phone is unique system-wide so it resolves a single driver at login.
        $exists = Driver::query()
            ->where('phone', $phone)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        abort_if($exists, 422, __('api.delivery_driver_phone_taken'));
    }
}

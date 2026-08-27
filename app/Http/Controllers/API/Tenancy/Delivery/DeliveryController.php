<?php

namespace App\Http\Controllers\API\Tenancy\Delivery;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Delivery\DeliveryInventoryRequest;
use App\Http\Requests\Delivery\DeliveryZoneRequest;
use App\Http\Requests\Delivery\DriverRequest;
use App\Http\Requests\Delivery\StoreDeliveryRequestRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\DeliveryRequest;
use App\Models\DeliveryZone;
use App\Models\Driver;
use App\Models\Order;
use App\Services\Delivery\DeliveryRequestService;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\DeliverySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rules\Enum;

class DeliveryController extends TenantController
{
    private const FEATURE = 'delivery';

    public function __construct(
        private readonly DeliverySettingsService $settings,
        private readonly DeliveryService $delivery,
        private readonly DeliveryRequestService $requests,
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
    | Requests
    |--------------------------------------------------------------------------
    */
    public function requests(PageRequest $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $query = DeliveryRequest::query()
            ->inBranches($this->readBranchIds())
            ->with(['customer:id,name,phone', 'driver:id,name,phone'])
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id')
            ->limit(200);

        return successResponse($query->get());
    }

    public function showRequest(DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);
        $this->assertInReadScope($delivery);

        $data = $delivery->load('customer', 'driver', 'order', 'zone', 'history')->toArray();
        $data['pickup_photo_signed_url'] = $this->signedPhotoUrl($delivery->pickup_photo_url);
        $data['delivery_photo_signed_url'] = $this->signedPhotoUrl($delivery->delivery_photo_url);

        return successResponse($data);
    }

    public function storeRequest(StoreDeliveryRequestRequest $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $customer = $this->ownedCustomer((int) $request->input('customer_id'));
        $this->assertOrderOwned($request->input('order_id'));
        $zone = $this->resolveZone($request->input('zone_id'));

        $created = $this->requests->createRequests(
            $this->organizationId(),
            $this->writeBranchId(),
            $customer,
            $request->input('type'),
            $zone,
            $request->validated(),
            $this->settings->resolve($this->organizationId()),
            DeliverySourceEnum::Staff,
            $this->staff()->getKey(),
        );

        return successResponse($created, __('api.created_success'), 201);
    }

    /**
     * Assign a driver/external app, adjust the fee, and/or submit a status.
     */
    public function updateRequest(Request $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);
        $this->assertInReadScope($delivery);

        $data = $request->validate([
            'driver_id' => ['nullable', 'integer'],
            'external_provider' => ['nullable', 'string', 'max:60'],
            'external_ref' => ['nullable', 'string', 'max:120'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', new Enum(DeliveryStatusEnum::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $settings = $this->settings->resolve($this->organizationId());

        if (isset($data['driver_id'])) {
            $delivery = $this->requests->assignDriver($delivery, (int) $data['driver_id'], $this->readBranchIds(), $settings);
        }

        if (isset($data['external_provider'])) {
            $delivery = $this->requests->assignExternal($delivery, $data['external_provider'], $data['external_ref'] ?? null, isset($data['fee']) ? (float) $data['fee'] : null, $settings);
        } elseif (isset($data['fee'])) {
            $delivery->forceFill(['fee' => round((float) $data['fee'], 2)])->save();
        }

        if (isset($data['status'])) {
            $delivery = $this->requests->submitStatus($delivery, DeliveryStatusEnum::from($data['status']), $this->staff()->getKey(), $settings);
        }

        return successResponse($delivery->refresh()->load('history'), __('api.updated_success'));
    }

    /**
     * Unified staff action: confirm arrival, or flag invoice approval.
     */
    public function requestAction(Request $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);
        $this->assertInReadScope($delivery);

        $data = $request->validate([
            'action' => ['required', 'in:arrive,require_invoice_approval'],
            'require' => ['nullable', 'boolean'],
        ]);

        $userId = $this->staff()->getKey();

        $delivery = match ($data['action']) {
            'arrive' => $this->requests->arrive($delivery, $userId),
            'require_invoice_approval' => $this->requests->requireInvoiceApproval($delivery, (bool) ($data['require'] ?? true), $userId),
        };

        return successResponse($delivery, __('api.updated_success'));
    }

    /**
     * Turn the basket into a priced invoice and link it to the request.
     */
    public function inventory(DeliveryInventoryRequest $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);
        $this->assertInReadScope($delivery);

        $branch = Branch::query()->where('organization_id', $this->organizationId())->findOrFail($delivery->branch_id);

        $delivery = $this->requests->inventory(
            $delivery,
            $branch,
            $request->input('items'),
            $request->input('notes'),
            $this->staff()->getKey(),
            $this->settings->resolve($this->organizationId()),
        );

        return successResponse($delivery->load('order'), __('api.updated_success'));
    }

    public function stats(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        return successResponse($this->requests->stats($this->readBranchIds()));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    /**
     * A 12-hour signed URL for a stored proof photo (the signature is the authorization).
     */
    private function signedPhotoUrl(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        return URL::temporarySignedRoute('delivery.photo', now()->addHours(12), ['name' => $name]);
    }

    private function ownedCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->forOrganization($this->organizationId())->find($customerId);
        abort_if($customer === null, 404, __('api.record_not_found'));

        return $customer;
    }

    private function assertOrderOwned(?int $orderId): void
    {
        if ($orderId === null) {
            return;
        }

        // A referenced order must belong to the caller's branches — never leak another
        // organization's financials by attaching to a foreign order.
        $exists = Order::query()->inBranches($this->readBranchIds())->whereKey($orderId)->exists();
        abort_unless($exists, 404, __('api.record_not_found'));
    }

    private function resolveZone(?int $zoneId): ?DeliveryZone
    {
        if ($zoneId === null) {
            return null;
        }

        $zone = DeliveryZone::query()
            ->forOrganization($this->organizationId())
            ->inBranches($this->readBranchIds())
            ->find($zoneId);
        abort_if($zone === null, 404, __('api.record_not_found'));

        return $zone;
    }

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

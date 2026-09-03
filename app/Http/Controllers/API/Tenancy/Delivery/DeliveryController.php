<?php

namespace App\Http\Controllers\API\Tenancy\Delivery;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Enum\Delivery\DeliveryStatusEnum;
use App\Filters\Delivery\DeliveryRequestFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Delivery\AssignDeliveryRequest;
use App\Http\Requests\Delivery\DeliveryActionRequest;
use App\Http\Requests\Delivery\DeliveryInventoryRequest;
use App\Http\Requests\Delivery\DeliverySettingsRequest;
use App\Http\Requests\Delivery\DeliveryZoneRequest;
use App\Http\Requests\Delivery\DriverRequest;
use App\Http\Requests\Delivery\StoreDeliveryRequestRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Delivery\DeliveryRequestResource;
use App\Http\Resources\Delivery\DeliveryZoneResource;
use App\Http\Resources\Delivery\DriverResource;
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
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Facades\URL;

class DeliveryController extends TenantController
{
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

        return successResponse($this->settings->resolve($this->organizationId()));
    }

    public function updateSettings(DeliverySettingsRequest $request): JsonResponse
    {
        // Only the general manager may change delivery configuration.

        return successResponse(
            $this->settings->save($this->organizationId(), $request->validated()),
            __('api.updated_success'),
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Zones
    |--------------------------------------------------------------------------
    */
    public function zones(): JsonResponse
    {
        $this->staff();

        // A branch's zones are a small, hand-curated list, so this is deliberately not
        // paginated — the counter needs all of them to price a delivery.
        $zones = DeliveryZone::query()
            ->forOrganization($this->organizationId())
            ->inBranches($this->readBranchIds())
            ->orderBy('sort_order')
            ->get();

        return successResponse(DeliveryZoneResource::collection($zones));
    }

    public function storeZone(DeliveryZoneRequest $request): JsonResponse
    {

        $branchId = $this->writeBranchId();
        $sortOrder = DeliveryZone::query()->where('branch_id', $branchId)->count();

        $zone = DeliveryZone::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $branchId,
            'sort_order' => $sortOrder,
        ]);

        return successResponse(new DeliveryZoneResource($zone), __('api.created_success'), 201);
    }

    public function updateZone(DeliveryZoneRequest $request, DeliveryZone $zone): JsonResponse
    {
        $this->assertOwned($zone);

        $zone->update($request->validated());

        return successResponse(new DeliveryZoneResource($zone->refresh()), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Drivers
    |--------------------------------------------------------------------------
    */
    public function drivers(): JsonResponse
    {
        $this->staff();

        $settings = $this->settings->resolve($this->organizationId());
        // The assignable set is small by nature (a branch's own drivers plus any platform
        // drivers covering it), and the counter picks from all of them at once.
        $drivers = $this->delivery->assignableDrivers($this->readBranchIds(), $settings)
            ->orderBy('name')
            ->get();

        return successResponse(DriverResource::collection($drivers));
    }

    public function storeDriver(DriverRequest $request): JsonResponse
    {

        $driver = Driver::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
            'is_platform' => false,
        ]);

        return successResponse(new DriverResource($driver), __('api.created_success'), 201);
    }

    public function updateDriver(DriverRequest $request, Driver $driver): JsonResponse
    {
        $this->assertOwnedDriver($driver);

        $driver->update($request->validated());

        return successResponse(new DriverResource($driver->refresh()), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Requests
    |--------------------------------------------------------------------------
    */
    public function requests(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = app(Pipeline::class)
            ->send(DeliveryRequest::query()
                ->inBranches($this->readBranchIds())
                ->with([
                    'customer:id,name,phone',
                    'driver:id,name,phone',
                    'zone',
                    'order:id,order_no,status,payment_status,grand_total,paid_total',
                ]))
            ->through([DeliveryRequestFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, DeliveryRequestResource::class));
    }

    public function showRequest(DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($delivery);

        $delivery->load('customer', 'driver', 'order', 'zone', 'history');

        return successResponse((new DeliveryRequestResource($delivery))->withSignedPhotos([
            'pickup' => $this->signedPhotoUrl($delivery->pickup_photo_url),
            'delivery' => $this->signedPhotoUrl($delivery->delivery_photo_url),
        ]));
    }

    public function storeRequest(StoreDeliveryRequestRequest $request): JsonResponse
    {
        $this->staff();

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
    public function updateRequest(AssignDeliveryRequest $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($delivery);

        $data = $request->validated();

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
    public function requestAction(DeliveryActionRequest $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($delivery);

        $userId = $this->staff()->getKey();

        $delivery = match ($request->action()) {
            'arrive' => $this->requests->arrive($delivery, $userId),
            'require_invoice_approval' => $this->requests->requireInvoiceApproval($delivery, $request->requiresApproval(), $userId),
        };

        return successResponse($delivery, __('api.updated_success'));
    }

    /**
     * Turn the basket into a priced invoice and link it to the request.
     */
    public function inventory(DeliveryInventoryRequest $request, DeliveryRequest $delivery): JsonResponse
    {
        $this->staff();
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
}

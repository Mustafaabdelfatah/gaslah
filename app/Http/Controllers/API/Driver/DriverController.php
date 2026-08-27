<?php

namespace App\Http\Controllers\API\Driver;

use App\Http\Requests\Driver\AdvanceDeliveryRequest;
use App\Http\Requests\Driver\RejectDeliveryRequest;
use App\Http\Requests\Driver\StoreDeliveryPhotoRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Delivery\DriverJobResource;
use App\Models\DeliveryRequest;
use App\Services\Delivery\DriverService;
use Illuminate\Http\JsonResponse;

class DriverController extends DriverBaseController
{
    public function __construct(private readonly DriverService $drivers)
    {
        parent::__construct();
    }

    public function me(): JsonResponse
    {
        return successResponse($this->driver());
    }

    /**
     * The driver's own requests — open ones first, then most recent (up to 100).
     */
    public function requests(PageRequest $request): JsonResponse
    {
        // Live jobs first: a driver opens the app to see what is still on their hands, not
        // what they finished yesterday.
        $query = DeliveryRequest::query()
            ->where('driver_id', $this->driver()->getKey())
            ->with(['customer:id,name,phone', 'order:id,order_no,grand_total,payment_status'])
            ->orderByRaw("CASE WHEN status IN ('assigned','picked_up','out_for_delivery') THEN 0 ELSE 1 END")
            ->latest('id');

        return successResponse(wrapPaginate($query, DriverJobResource::class));
    }

    public function accept(int $id): JsonResponse
    {
        return successResponse($this->drivers->accept($this->ownedRequest($id)), __('api.delivery_accepted'));
    }

    public function reject(RejectDeliveryRequest $request, int $id): JsonResponse
    {
        return successResponse($this->drivers->reject($this->ownedRequest($id), $request->reason()), __('api.delivery_rejected'));
    }

    public function arrive(int $id): JsonResponse
    {
        return successResponse($this->drivers->arrive($this->ownedRequest($id)), __('api.delivery_arrived'));
    }

    public function photo(StoreDeliveryPhotoRequest $request, int $id): JsonResponse
    {
        return successResponse(
            $this->drivers->storePhoto($this->ownedRequest($id), $request->kind(), $request->image()),
            __('api.updated_success'),
        );
    }

    public function advance(AdvanceDeliveryRequest $request, int $id): JsonResponse
    {
        $updated = $this->drivers->advance($this->ownedRequest($id), $request->status());

        return successResponse($updated, __('api.updated_success'));
    }
}

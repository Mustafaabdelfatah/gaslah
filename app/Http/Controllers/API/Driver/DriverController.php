<?php

namespace App\Http\Controllers\API\Driver;

use App\Enum\Delivery\DeliveryStatusEnum;
use App\Services\Delivery\DriverService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

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
    public function requests(): JsonResponse
    {
        $requests = $this->driver()->requests()
            ->with(['customer:id,name,phone', 'order:id,order_no,grand_total,payment_status'])
            ->orderByRaw("CASE WHEN status IN ('assigned','picked_up','out_for_delivery') THEN 0 ELSE 1 END")
            ->latest('id')
            ->limit(100)
            ->get();

        return successResponse($requests);
    }

    public function accept(int $id): JsonResponse
    {
        return successResponse($this->drivers->accept($this->ownedRequest($id)), __('api.delivery_accepted'));
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        return successResponse($this->drivers->reject($this->ownedRequest($id), $data['reason'] ?? null), __('api.delivery_rejected'));
    }

    public function arrive(int $id): JsonResponse
    {
        return successResponse($this->drivers->arrive($this->ownedRequest($id)), __('api.delivery_arrived'));
    }

    public function photo(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'in:pickup,delivery'],
            'image' => ['required', 'string'],
        ]);

        return successResponse($this->drivers->storePhoto($this->ownedRequest($id), $data['kind'], $data['image']), __('api.updated_success'));
    }

    public function advance(Request $request, int $id): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', new Enum(DeliveryStatusEnum::class)],
        ]);

        $updated = $this->drivers->advance($this->ownedRequest($id), DeliveryStatusEnum::from($data['status']));

        return successResponse($updated, __('api.updated_success'));
    }
}

<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\ActiveFilter;
use App\Filters\Global\NameFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformDeviceRequest;
use App\Http\Resources\Platform\PlatformDeviceResource;
use App\Models\PlatformDevice;
use App\Trait\Global\HasToggleActiveMethods;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The platform's hardware catalogue. Any admin may read it — it feeds the sale form —
 * while editing it is a commercial decision (manage_subscriptions). Devices are retired
 * through is_active rather than deleted, since past invoices name them.
 */
class AdminDeviceController extends BaseController
{
    use HasToggleActiveMethods;

    public function __construct()
    {
        parent::__construct();
        $this->model = PlatformDevice::class;
        $this->enableTogglePolicy(false);
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformDevice::query()->orderBy('sort_order'))
            ->through([NameFilter::class, ActiveFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformDeviceResource::class));
    }

    public function store(PlatformDeviceRequest $request): JsonResponse
    {
        $device = PlatformDevice::create($request->validated());

        return successResponse(new PlatformDeviceResource($device), __('api.created_success'), 201);
    }

    public function show(PlatformDevice $device): JsonResponse
    {
        return successResponse(new PlatformDeviceResource($device));
    }

    public function update(PlatformDeviceRequest $request, PlatformDevice $device): JsonResponse
    {
        $device->update($request->validated());

        return successResponse(new PlatformDeviceResource($device->refresh()), __('api.updated_success'));
    }
}

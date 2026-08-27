<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\DisposeAssetRequest;
use App\Http\Requests\Accounting\StoreAssetRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\FixedAsset;
use App\Services\Accounting\AssetService;
use Illuminate\Http\JsonResponse;

class AssetController extends TenantController
{
    public function __construct(private readonly AssetService $assets)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {

        $query = FixedAsset::query()
            ->where('organization_id', $this->organizationId())
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->latest('id');

        return successResponse(wrapPaginate($query));
    }

    public function store(StoreAssetRequest $request): JsonResponse
    {

        $asset = $this->assets->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
        ]);

        return successResponse($asset, __('api.created_success'), 201);
    }

    public function depreciate(FixedAsset $asset): JsonResponse
    {
        $this->assertOwned($asset);

        return successResponse($this->assets->depreciate($asset), __('api.updated_success'));
    }

    public function dispose(DisposeAssetRequest $request, FixedAsset $asset): JsonResponse
    {
        $this->assertOwned($asset);

        return successResponse($this->assets->dispose($asset, $request->validated()), __('api.updated_success'));
    }

    public function destroy(FixedAsset $asset): JsonResponse
    {
        $this->assertOwned($asset);

        $this->assets->delete($asset);

        return successResponse(msg: __('api.deleted_success'));
    }
}

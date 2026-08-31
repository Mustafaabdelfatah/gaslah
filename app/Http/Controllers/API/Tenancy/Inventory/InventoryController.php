<?php

namespace App\Http\Controllers\API\Tenancy\Inventory;

use App\Filters\Global\NameFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Inventory\InventoryItemRequest;
use App\Http\Resources\Inventory\InventoryItemResource;
use App\Http\Resources\Inventory\PurchaseOrderResource;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Services\Inventory\UnitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

class InventoryController extends TenantController
{
    public function __construct(private readonly UnitService $units)
    {
        parent::__construct();
    }

    public function items(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(InventoryItem::query()
                ->forOrganization($this->organizationId())
                ->inBranches($this->readBranchIds())
                ->where('is_active', true)
                ->with('unit:id,name,symbol'))
            ->through([NameFilter::class, OrderByFilter::class])
            ->thenReturn();

        // The low-stock count is over the whole tenant, not just the page, so the shelf
        // badge stays right however the list is paged.
        $lowStock = (clone $query)->lowStock()->count();

        // The units ride along so the item form can be built from one response —
        // they have no endpoint of their own, and an item has to be counted in
        // something.
        return successResponse(wrapPaginate($query, InventoryItemResource::class, [
            'low_stock' => $lowStock,
            'units' => $this->units->forOrganization($this->organizationId()),
        ]));
    }

    public function storeItem(InventoryItemRequest $request): JsonResponse
    {

        $item = InventoryItem::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
        ]);

        return successResponse($item, __('api.created_success'), 201);
    }

    public function updateItem(InventoryItemRequest $request, InventoryItem $item): JsonResponse
    {
        $this->assertInReadScope($item);

        $item->update($request->validated());

        return successResponse($item->refresh(), __('api.updated_success'));
    }

    public function lowStock(): JsonResponse
    {

        $items = InventoryItem::query()
            ->forOrganization($this->organizationId())
            ->inBranches($this->readBranchIds())
            ->where('is_active', true)
            ->lowStock()
            ->with('unit:id,name,symbol')
            ->orderBy('name')
            ->get();

        return successResponse(InventoryItemResource::collection($items));
    }

    /**
     * Purchase orders — read-only.
     */
    public function purchaseOrders(PageRequest $request): JsonResponse
    {
        $query = PurchaseOrder::query()
            ->inBranches($this->readBranchIds())
            ->with(['supplier:id,name'])
            ->withCount('items')
            ->latest('id');

        return successResponse(wrapPaginate($query, PurchaseOrderResource::class));
    }
}

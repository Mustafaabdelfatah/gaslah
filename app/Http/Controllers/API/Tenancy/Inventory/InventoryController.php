<?php

namespace App\Http\Controllers\API\Tenancy\Inventory;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Inventory\InventoryItemRequest;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use Illuminate\Http\JsonResponse;

class InventoryController extends TenantController
{
    public function items(): JsonResponse
    {

        $items = InventoryItem::query()
            ->forOrganization($this->organizationId())
            ->inBranches($this->readBranchIds())
            ->where('is_active', true)
            ->with('unit:id,name,symbol')
            ->orderBy('name')
            ->get();

        return successResponse([
            'items' => $items,
            'total' => $items->count(),
            'low_stock' => $items->where('low_stock', true)->count(),
        ]);
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

        return successResponse($items);
    }

    /**
     * Purchase orders — read-only.
     */
    public function purchaseOrders(): JsonResponse
    {

        $orders = PurchaseOrder::query()
            ->inBranches($this->readBranchIds())
            ->with(['supplier:id,name'])
            ->withCount('items')
            ->latest('id')
            ->limit(50)
            ->get();

        $data = $orders->map(fn (PurchaseOrder $po) => [
            'id' => $po->getKey(),
            'branch_id' => $po->branch_id,
            'supplier_id' => $po->supplier_id,
            'supplier_name' => $po->supplier?->name,
            'status' => $po->status,
            'total' => round((float) $po->total, 2),
            'created_at' => $po->created_at,
            'received_at' => $po->received_at,
            'items_count' => $po->items_count,
        ]);

        return successResponse($data);
    }
}

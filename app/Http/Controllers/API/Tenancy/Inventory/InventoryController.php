<?php

namespace App\Http\Controllers\API\Tenancy\Inventory;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\InventoryItem;
use App\Models\PurchaseOrder;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends TenantController
{
    private const FEATURE = 'inventory';

    public function items(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

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

    public function storeItem(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $data = $this->validated($request);
        $this->assertUnitInOrg((int) $data['unit_id']);

        $item = InventoryItem::query()->create([
            ...$data,
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
        ]);

        return successResponse($item, __('api.created_success'), 201);
    }

    public function updateItem(Request $request, InventoryItem $item): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($item);

        $data = $this->validated($request, updating: true);

        if (isset($data['unit_id'])) {
            $this->assertUnitInOrg((int) $data['unit_id']);
        }

        $item->update($data);

        return successResponse($item->refresh(), __('api.updated_success'));
    }

    public function lowStock(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

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
        $this->staff();
        $this->requireFeature(self::FEATURE);

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

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $updating = false): array
    {
        $required = $updating ? 'sometimes' : 'required';

        return $request->validate([
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'unit_id' => [$required, 'integer'],
            'sku' => ['nullable', 'string', 'max:80'],
            'cost' => ['nullable', 'numeric', 'min:0'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    private function assertUnitInOrg(int $unitId): void
    {
        $exists = Unit::query()->forOrganization($this->organizationId())->whereKey($unitId)->exists();
        abort_unless($exists, 422, __('api.inventory_unit_not_in_org'));
    }

    private function assertOwned(InventoryItem $item): void
    {
        abort_unless(in_array($item->branch_id, $this->readBranchIds(), true), 404, __('api.record_not_found'));
    }
}

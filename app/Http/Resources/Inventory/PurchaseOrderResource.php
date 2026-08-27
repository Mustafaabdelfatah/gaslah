<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A purchase order. Read-only for now: goods-receipt and stock movement are documented
 * gaps, so nothing here writes back to inventory.
 */
class PurchaseOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'status' => $this->status,

            'supplier_id' => $this->supplier_id,
            'supplier_name' => $this->whenLoaded('supplier', fn () => $this->supplier?->name),

            'total' => round((float) $this->total, 2),
            'items_count' => $this->whenCounted('items'),

            'received_at' => $this->received_at,
            'created_at' => $this->created_at,
        ];
    }
}

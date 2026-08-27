<?php

namespace App\Http\Resources\Inventory;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A stocked item. `low_stock` is computed against the reorder level, so the shelf-check
 * screen never has to work it out.
 */
class InventoryItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,

            'unit_id' => $this->unit_id,
            'unit' => $this->whenLoaded('unit', fn () => $this->unit === null ? null : [
                'id' => $this->unit->id,
                'name' => $this->unit->name,
                'symbol' => $this->unit->symbol,
            ]),

            'quantity' => $this->quantity,
            'reorder_level' => $this->reorder_level,
            'low_stock' => (bool) $this->low_stock,
            'cost' => $this->cost,

            'branch_id' => $this->branch_id,
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at,
        ];
    }
}

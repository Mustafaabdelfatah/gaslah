<?php

namespace App\Http\Resources\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market product. The supplier appears as a name and city only — a buyer browsing the
 * market has no business seeing the supplier's contact details or commission terms.
 */
class MarketProductResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'category' => $this->category,
            'description' => $this->description,

            'unit' => $this->unit,
            'price' => $this->price,
            'stock' => $this->stock,
            'is_unlimited_stock' => $this->stock === null,
            'image_url' => $this->image_url,

            'is_active' => (bool) $this->is_active,
            'supplier_id' => $this->supplier_id,
            'supplier' => $this->whenLoaded('supplier', fn () => $this->supplier === null ? null : [
                'id' => $this->supplier->id,
                'name' => $this->supplier->name,
                'city' => $this->supplier->city,
            ]),

            'created_at' => $this->created_at,
        ];
    }
}

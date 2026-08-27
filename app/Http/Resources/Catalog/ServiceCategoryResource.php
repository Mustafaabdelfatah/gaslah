<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A catalogue category and, when loaded, the products beneath it.
 */
class ServiceCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'icon' => $this->icon,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,

            'products' => ProductResource::collection($this->whenLoaded('products')),
        ];
    }
}

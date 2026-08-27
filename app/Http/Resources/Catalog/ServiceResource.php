<?php

namespace App\Http\Resources\Catalog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One price cell of the catalogue grid: what a given product costs under a given service
 * type, normally and express.
 */
class ServiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'product_id' => $this->product_id,
            'category_id' => $this->category_id,
            'service_type' => $this->service_type,
            'pricing_type' => $this->pricing_type,

            'base_price' => $this->base_price,
            'express_surcharge' => $this->express_surcharge,
            'express_price' => $this->unitPriceFor(true),
            'is_express_available' => (bool) $this->is_express_available,

            'is_active' => (bool) $this->is_active,
        ];
    }
}

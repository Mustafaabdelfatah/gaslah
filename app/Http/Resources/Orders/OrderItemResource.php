<?php

namespace App\Http\Resources\Orders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One priced line of an order. Prices are the ones computed server-side at checkout, kept
 * as a snapshot so a later catalogue change never rewrites a past sale.
 */
class OrderItemResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'service_id' => $this->service_id,
            'service_name' => $this->whenLoaded('service', fn () => $this->service?->name),
            'garment_type_id' => $this->garment_type_id,
            'garment_type_name' => $this->whenLoaded('garmentType', fn () => $this->garmentType?->name),

            'is_express' => (bool) $this->is_express,
            'quantity' => $this->quantity,
            'unit_price' => $this->unit_price,
            'line_total' => $this->line_total,
            'notes' => $this->notes,
        ];
    }
}

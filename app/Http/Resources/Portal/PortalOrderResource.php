<?php

namespace App\Http\Resources\Portal;

use App\Http\Resources\Orders\OrderItemResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as its own customer sees it on the portal.
 *
 * Deliberately narrower than the staff view: no cashier, no branch, no internal notes —
 * the customer needs to know what they ordered, what it costs and where it is.
 */
class PortalOrderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_no' => $this->order_no,
            'status' => $this->status,
            'payment_status' => $this->payment_status,

            'grand_total' => round((float) $this->grand_total, 2),
            'paid_total' => round((float) $this->paid_total, 2),
            'remaining' => $this->remaining(),

            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            'due_at' => $this->due_at,
            'delivered_at' => $this->delivered_at,
            'created_at' => $this->created_at,
        ];
    }
}

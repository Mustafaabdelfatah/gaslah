<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A job as the driver's own app shows it.
 *
 * Narrower than the counter's view: the driver needs the address, who to meet and what to
 * collect — not the tenant's fees, workflow flags or internal notes.
 */
class DriverJobResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,

            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'order' => $this->whenLoaded('order', fn () => $this->order === null ? null : [
                'id' => $this->order->id,
                'order_no' => $this->order->order_no,
                'grand_total' => $this->order->grand_total,
                'payment_status' => $this->order->payment_status,
            ]),

            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'notes' => $this->notes,

            'scheduled_at' => $this->scheduled_at,
            'assigned_at' => $this->assigned_at,
            'accepted_at' => $this->accepted_at,
            'arrived_at' => $this->arrived_at,
            'completed_at' => $this->completed_at,

            'invoice_approval_required' => (bool) $this->invoice_approval_required,
            'invoice_approved_at' => $this->invoice_approved_at,
            'has_pickup_photo' => $this->pickup_photo_url !== null,
            'has_delivery_photo' => $this->delivery_photo_url !== null,

            'created_at' => $this->created_at,
        ];
    }
}

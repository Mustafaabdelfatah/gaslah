<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryRequestSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
            ]),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver === null ? null : [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
            ]),
            'order' => $this->whenLoaded('order', fn () => $this->order === null ? null : [
                'id' => $this->order->id,
                'order_no' => $this->order->order_no,
            ]),
            'zone' => $this->whenLoaded('zone', fn () => $this->zone === null ? null : [
                'id' => $this->zone->id,
                'name' => $this->zone->name,
            ]),
            'fee' => $this->fee,
            'address' => $this->address,
            'maps_link' => $this->lat !== null && $this->lng !== null
                ? 'https://www.google.com/maps/search/?api=1&query='.$this->lat.','.$this->lng
                : null,
            'next_statuses' => array_map(
                static fn ($status) => $status->value,
                $this->status->allowedNext($this->type),
            ),
            'accepted_at' => $this->accepted_at,
            'arrived_at' => $this->arrived_at,
            'external_provider' => $this->external_provider,
            'has_pickup_photo' => $this->pickup_photo_url !== null,
            'has_delivery_photo' => $this->delivery_photo_url !== null,
            'invoice_approval_required' => (bool) $this->invoice_approval_required,
            'invoice_approved_at' => $this->invoice_approved_at,
            'created_at' => $this->created_at,
        ];
    }
}

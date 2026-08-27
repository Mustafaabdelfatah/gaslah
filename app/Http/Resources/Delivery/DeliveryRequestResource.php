<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pickup or delivery job.
 *
 * Proof photos are never exposed as raw paths — the detail view attaches time-limited
 * signed URLs instead, so a photo cannot be reached by guessing a filename.
 */
class DeliveryRequestResource extends JsonResource
{
    /**
     * @param  array{pickup: string|null, delivery: string|null}|null  $signedPhotos
     */
    public function __construct($resource, private readonly ?array $signedPhotos = null)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'status' => $this->status,
            'source' => $this->source,

            'branch_id' => $this->branch_id,
            'customer_id' => $this->customer_id,
            'customer' => $this->whenLoaded('customer', fn () => $this->customer === null ? null : [
                'id' => $this->customer->id,
                'name' => $this->customer->name,
                'phone' => $this->customer->phone,
            ]),
            'driver_id' => $this->driver_id,
            'driver' => $this->whenLoaded('driver', fn () => $this->driver === null ? null : [
                'id' => $this->driver->id,
                'name' => $this->driver->name,
                'phone' => $this->driver->phone,
            ]),
            'order_id' => $this->order_id,
            'order' => $this->whenLoaded('order'),
            'zone_id' => $this->zone_id,
            'zone' => new DeliveryZoneResource($this->whenLoaded('zone')),

            'fee' => $this->fee,
            'fee_applied_to_order' => (bool) $this->fee_applied_to_order,

            'address' => $this->address,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'notes' => $this->notes,

            'scheduled_at' => $this->scheduled_at,
            'assigned_at' => $this->assigned_at,
            'accepted_at' => $this->accepted_at,
            'rejected_at' => $this->rejected_at,
            'reject_reason' => $this->reject_reason,
            'arrived_at' => $this->arrived_at,
            'completed_at' => $this->completed_at,

            'inventory_done_at' => $this->inventory_done_at,
            'inventory_notes' => $this->inventory_notes,
            'invoice_approval_required' => (bool) $this->invoice_approval_required,
            'invoice_approved_at' => $this->invoice_approved_at,

            'external_provider' => $this->external_provider,
            'external_ref' => $this->external_ref,

            'has_pickup_photo' => $this->pickup_photo_url !== null,
            'has_delivery_photo' => $this->delivery_photo_url !== null,
            'pickup_photo_signed_url' => $this->when($this->signedPhotos !== null, fn () => $this->signedPhotos['pickup']),
            'delivery_photo_signed_url' => $this->when($this->signedPhotos !== null, fn () => $this->signedPhotos['delivery']),

            'history' => $this->whenLoaded('history'),
            'created_at' => $this->created_at,
        ];
    }
}

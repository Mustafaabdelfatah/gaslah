<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device-sale tax invoice.
 *
 * `items` is the snapshot taken when the sale was drafted, not a live catalogue read — a
 * later re-pricing must never change what a past invoice says was charged.
 */
class DeviceSaleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'status' => $this->status,

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'is_external_buyer' => $this->organization_id === null,

            'buyer_name' => $this->buyer_name,
            'buyer_vat' => $this->buyer_vat,
            'seller_name' => $this->seller_name,
            'seller_vat' => $this->seller_vat,

            'items' => $this->items ?? [],
            'notes' => $this->notes,

            'subtotal' => $this->subtotal,
            'vat' => $this->vat,
            'total' => $this->total,

            'payment_method' => $this->payment_method,
            'bank_name' => $this->bank_name,
            'transfer_ref' => $this->transfer_ref,
            'gateway_name' => $this->gateway_name,

            'icv' => $this->icv,
            'pih' => $this->pih,
            'hash' => $this->hash,
            'qr' => $this->qr,

            'confirmed_at' => $this->confirmed_at,
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => $this->confirmedBy?->name),
            'issued_at' => $this->issued_at,
            'created_at' => $this->created_at,
        ];
    }
}

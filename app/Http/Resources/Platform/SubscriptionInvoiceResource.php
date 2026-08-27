<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform subscription invoice.
 *
 * A draft carries no chain slot and no QR — those fields stay null until it is confirmed,
 * at which point the row becomes an immutable ZATCA document.
 */
class SubscriptionInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'status' => $this->status,

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->id,
                'name' => $this->organization?->name,
            ]),
            'subscription_id' => $this->subscription_id,
            'charge_id' => $this->charge_id,

            'seller_name' => $this->seller_name,
            'seller_vat' => $this->seller_vat,
            'buyer_name' => $this->buyer_name,
            'buyer_vat' => $this->buyer_vat,

            'plan_name' => $this->plan_name,
            'cycle' => $this->cycle,

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
            'confirmed_by' => $this->whenLoaded('confirmedBy', fn () => [
                'id' => $this->confirmedBy?->id,
                'name' => $this->confirmedBy?->name,
            ]),
            'issued_at' => $this->issued_at,
            'created_at' => $this->created_at,
        ];
    }
}

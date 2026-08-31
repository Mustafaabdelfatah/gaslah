<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform-subscription invoice as the tenant it was issued to sees it.
 *
 * Narrower than the operator's view on purpose: the ZATCA chain fields (icv, pih, hash)
 * and the seller's own VAT registration are the platform's bookkeeping, not the buyer's.
 * What a tenant needs is what they were charged, for which plan, when, and how it was paid.
 */
class OrgSubscriptionInvoiceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'invoice_no' => $this->invoice_no,
            'status' => $this->status,

            'plan_name' => $this->plan_name,
            'cycle' => $this->cycle,

            'subtotal' => $this->subtotal,
            'vat' => $this->vat,
            'total' => $this->total,

            'payment_method' => $this->payment_method,
            'bank_name' => $this->bank_name,
            'transfer_ref' => $this->transfer_ref,
            'gateway_name' => $this->gateway_name,

            'issued_at' => $this->issued_at,
            'created_at' => $this->created_at,
        ];
    }
}

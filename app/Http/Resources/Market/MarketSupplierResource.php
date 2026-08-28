<?php

namespace App\Http\Resources\Market;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A market supplier's own profile, as their portal shows it.
 *
 * Commission terms are included here on purpose — a supplier is entitled to know what the
 * platform takes. The buyer-facing view (inside MarketProductResource) shows neither.
 */
class MarketSupplierResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'city' => $this->city,
            'description' => $this->description,
            'logo_url' => $this->logo_url,

            'status' => $this->status,
            'is_sellable' => $this->status->isSellable(),
            'approved_at' => $this->approved_at,

            'commission_type' => $this->commission_type,
            'commission_value' => $this->commission_value,

            'created_at' => $this->created_at,
        ];
    }
}

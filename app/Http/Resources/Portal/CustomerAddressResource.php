<?php

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A saved address on the customer portal.
 */
class CustomerAddressResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'district' => $this->district,
            'street' => $this->street,
            'details' => $this->details,
            'is_default' => (bool) $this->is_default,
            'created_at' => $this->created_at,
        ];
    }
}

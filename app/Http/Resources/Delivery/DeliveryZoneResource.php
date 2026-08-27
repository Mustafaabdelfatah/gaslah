<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A delivery zone: an area the branch covers, its fee, and roughly how long it takes.
 */
class DeliveryZoneResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'name' => $this->name,
            'name_en' => $this->name_en,
            'fee' => $this->fee,
            'postal_codes' => $this->postal_codes ?? [],
            'eta_minutes' => $this->eta_minutes,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,
        ];
    }
}

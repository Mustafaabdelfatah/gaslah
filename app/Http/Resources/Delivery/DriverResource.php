<?php

namespace App\Http\Resources\Delivery;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A driver, as staff see them when assigning work.
 *
 * `is_platform` matters at the counter: a platform driver is assignable but is not the
 * tenant's to edit or deactivate.
 */
class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'vehicle' => $this->vehicle,
            'coverage_region' => $this->coverage_region,

            'branch_id' => $this->branch_id,
            'is_platform' => (bool) $this->is_platform,
            'is_active' => (bool) $this->is_active,
            'notes' => $this->notes,

            'created_at' => $this->created_at,
        ];
    }
}

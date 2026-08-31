<?php

namespace App\Http\Resources\Tenancy;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A branch as its management screen shows it: the record plus the headcount and the
 * traffic behind it, which is what tells an owner whether it is worth keeping open.
 */
class OrganizationBranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'address' => $this->address,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,

            'employees_count' => (int) ($this->employees_count ?? 0),
            'customers_count' => (int) ($this->customers_count ?? 0),
            'orders_count' => (int) ($this->orders_count ?? 0),

            'created_at' => $this->created_at,
        ];
    }
}

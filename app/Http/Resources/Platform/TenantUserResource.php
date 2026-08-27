<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant's staff member as the platform console sees them, with the roles held across
 * that tenant's branches.
 */
class TenantUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'is_active' => (bool) $this->is_active,
            'roles' => $this->whenLoaded(
                'branches',
                fn () => $this->branches->pluck('pivot.role')->unique()->values(),
            ),
        ];
    }
}

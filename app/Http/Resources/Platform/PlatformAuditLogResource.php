<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry of the platform-admin audit trail.
 */
class PlatformAuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'meta' => $this->meta ?? [],
            'organization_id' => $this->organization_id,

            'admin' => $this->whenLoaded('admin', fn () => [
                'id' => $this->admin?->id,
                'name' => $this->admin?->name,
            ]),
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),

            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A broadcast banner. A null organization means every tenant sees it.
 */
class PlatformAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'level' => $this->level,

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization === null ? null : [
                'id' => $this->organization->id,
                'name' => $this->organization->name,
            ]),
            'targets_all_tenants' => $this->organization_id === null,

            'is_active' => (bool) $this->is_active,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,

            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at,
        ];
    }
}

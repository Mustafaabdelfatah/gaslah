<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One entry on a follow-up timeline.
 */
class CrmNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'body' => $this->body,

            'due_at' => $this->due_at,
            'done_at' => $this->done_at,
            'is_done' => $this->done_at !== null,
            // Only a task can be outstanding, and only once its deadline has passed.
            'is_overdue' => $this->kind->isCompletable()
                && $this->done_at === null
                && $this->due_at?->isPast() === true,

            'lead_id' => $this->lead_id,
            'lead' => $this->whenLoaded('lead', fn () => $this->lead?->business_name),

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),

            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Crm;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A lead on the pipeline board.
 */
class LeadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'business_name' => $this->business_name,
            'contact_name' => $this->contact_name,
            'phone' => $this->phone,
            'email' => $this->email,
            'city' => $this->city,
            'source' => $this->source,

            'stage' => $this->stage,
            'expected_mrr' => $this->expected_mrr,

            'owner_id' => $this->owner_id,
            'owner' => $this->whenLoaded('owner', fn () => $this->owner?->name),

            // Set once, on conversion. Its presence is what refuses a second one.
            'converted_organization_id' => $this->converted_organization_id,
            'is_converted' => $this->isConverted(),

            'lost_reason' => $this->lost_reason,
            'won_at' => $this->won_at,

            'notes_count' => $this->whenCounted('notes'),
            'notes' => CrmNoteResource::collection($this->whenLoaded('notes')),

            'created_at' => $this->created_at,
        ];
    }
}

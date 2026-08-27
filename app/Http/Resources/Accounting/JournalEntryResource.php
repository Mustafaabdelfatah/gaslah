<?php

namespace App\Http\Resources\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A posted journal entry with its lines.
 *
 * The source reference is exposed because it is the idempotency key: it says which
 * document produced the entry, and re-posting that document returns this same entry
 * rather than writing a second one.
 */
class JournalEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entry_no' => $this->entry_no,
            'date' => $this->date,
            'memo' => $this->memo,

            'source' => $this->source,
            'ref_type' => $this->ref_type,
            'ref_id' => $this->ref_id,

            'branch_id' => $this->branch_id,
            'created_by_id' => $this->created_by_id,

            'lines' => JournalLineResource::collection($this->whenLoaded('lines')),

            'created_at' => $this->created_at,
        ];
    }
}

<?php

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A support ticket as the tenant's own list shows it.
 */
class SupportTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subject' => $this->subject,
            'category' => $this->category,
            'status' => $this->status,
            'priority' => $this->priority,

            'last_reply_at' => $this->last_reply_at,
            'created_at' => $this->created_at,

            'messages' => SupportTicketMessageResource::collection($this->whenLoaded('messages')),
        ];
    }
}

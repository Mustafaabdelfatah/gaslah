<?php

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One message in a support thread.
 */
class SupportTicketMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author_type' => $this->author_type,
            // Null for the automatic acknowledgement, which nobody wrote, and for a
            // message whose author has since left.
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'body' => $this->body,
            'created_at' => $this->created_at,
        ];
    }
}

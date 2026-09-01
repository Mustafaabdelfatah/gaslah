<?php

namespace App\Http\Resources\Community;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One reply in a forum thread. The author is a name, not an account — the forum is
 * platform-wide and nothing about who someone works for belongs in another laundry's view.
 */
class ForumPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),
            'body' => $this->body,
            'status' => $this->status,
            'created_at' => $this->created_at,
        ];
    }
}

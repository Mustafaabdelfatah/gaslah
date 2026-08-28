<?php

namespace App\Http\Resources\Community;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A forum thread. The body is only carried on the detail view — a listing of a hundred
 * threads has no business shipping a hundred full posts.
 */
class ForumThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'status' => $this->status,

            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),
            'author' => $this->whenLoaded('author', fn () => $this->author?->name),

            'is_pinned' => (bool) $this->is_pinned,
            'is_closed' => (bool) $this->is_closed,
            'reply_count' => $this->reply_count,
            'view_count' => $this->view_count,

            'last_activity_at' => $this->last_activity_at,
            'created_at' => $this->created_at,
        ];
    }
}

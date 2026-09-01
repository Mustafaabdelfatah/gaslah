<?php

namespace App\Http\Resources\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An article as its author sees it: the editing surface, so it carries the draft body and
 * the status a reader is never told about.
 */
class AdminBlogPostResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'content' => $this->content,
            'cover_image_url' => $this->cover_image_url,
            'tags' => $this->tags ?? [],
            'status' => $this->status,

            'category_id' => $this->category_id,
            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),

            'view_count' => $this->view_count,
            'published_at' => $this->published_at,
            'created_at' => $this->created_at,
        ];
    }
}

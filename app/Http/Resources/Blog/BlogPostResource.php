<?php

namespace App\Http\Resources\Blog;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An article as a reader sees it. The card carries the excerpt; the full content is only
 * on the detail view, so a listing of sixty articles does not ship sixty essays.
 */
class BlogPostResource extends JsonResource
{
    private bool $detailed = false;

    /**
     * Carry the article body as well.
     */
    public function asDetail(): self
    {
        $this->detailed = true;

        return $this;
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'slug' => $this->slug,
            'excerpt' => $this->excerpt,
            'cover_image_url' => $this->cover_image_url,
            'tags' => $this->tags ?? [],

            'category' => $this->whenLoaded('category', fn () => $this->category === null ? null : [
                'id' => $this->category->id,
                'name' => $this->category->name,
                'slug' => $this->category->slug,
            ]),

            'content' => $this->when($this->detailed, fn () => $this->content),

            'published_at' => $this->published_at,
        ];
    }
}

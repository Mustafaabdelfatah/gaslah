<?php

namespace App\Http\Resources\Portal;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An announcement as the customer portal shows it — the carousel needs the copy and the
 * image, nothing about who wrote it or when it was scheduled.
 */
class PortalAnnouncementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'body' => $this->body,
            'image_url' => $this->image_url,
        ];
    }
}

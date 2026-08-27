<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A platform plan, with the subscriber counts and MRR the console listing adds.
 *
 * The commercial figures appear only when the query loaded them (whenCounted /
 * whenNotNull), so the same resource serves both the listing and a bare create response.
 */
class PlatformPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'name_en' => $this->name_en,

            'monthly_price' => $this->monthly_price,
            'yearly_price' => $this->yearly_price,

            'max_branches' => $this->max_branches,
            'max_users' => $this->max_users,

            'features' => $this->features ?? [],
            'feature_keys' => $this->feature_keys ?? [],

            'is_popular' => (bool) $this->is_popular,
            'is_active' => (bool) $this->is_active,
            'sort_order' => $this->sort_order,

            'subscribers' => $this->whenCounted('subscriptions'),
            'active_subscribers' => $this->when($this->active_count !== null, fn () => (int) $this->active_count),
            'mrr' => $this->when($this->mrr !== null, fn () => round((float) $this->mrr, 2)),

            'created_at' => $this->created_at,
        ];
    }
}

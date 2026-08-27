<?php

namespace App\Http\Resources\Subscriptions;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A customer package the laundry sells (distinct from PlatformPlan, which is what the
 * laundry itself subscribes to).
 */
class SubscriptionPlanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'cycle' => $this->cycle,

            'price' => $this->price,
            'quota' => $this->quota,
            'service_id' => $this->service_id,

            'auto_renew' => (bool) $this->auto_renew,
            'is_active' => (bool) $this->is_active,

            'created_at' => $this->created_at,
        ];
    }
}

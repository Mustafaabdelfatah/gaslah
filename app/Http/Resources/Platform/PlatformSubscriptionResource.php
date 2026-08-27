<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's platform subscription.
 *
 * `status` is the display status — a writable subscription whose period has lapsed reads
 * as EXPIRED — while `raw_status` keeps the stored value for the console.
 */
class PlatformSubscriptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'plan_id' => $this->plan_id,
            'plan' => new PlatformPlanResource($this->whenLoaded('plan')),

            'cycle' => $this->cycle,
            'status' => $this->displayStatus(),
            'raw_status' => $this->status,
            'price' => $this->price,

            'started_at' => $this->started_at,
            'current_period_end' => $this->current_period_end,
            'cancel_at_period_end' => (bool) $this->cancel_at_period_end,
            'canceled_at' => $this->canceled_at,

            'created_at' => $this->created_at,
        ];
    }
}

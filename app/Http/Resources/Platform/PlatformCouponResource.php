<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A subscription coupon, with the remaining redemptions the console needs to show.
 */
class PlatformCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'type' => $this->type,
            'value' => $this->value,

            'max_redemptions' => $this->max_redemptions,
            'redemptions' => $this->redemptions,
            'remaining' => $this->max_redemptions === null
                ? null
                : max(0, $this->max_redemptions - $this->redemptions),

            'applies_to_plan_id' => $this->applies_to_plan_id,
            'plan' => new PlatformPlanResource($this->whenLoaded('plan')),

            'expires_at' => $this->expires_at,
            'is_active' => (bool) $this->is_active,
            'is_redeemable' => $this->isRedeemable($this->applies_to_plan_id),
            'note' => $this->note,

            'created_at' => $this->created_at,
        ];
    }
}

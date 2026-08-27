<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;

/**
 * The tenant drill-down: everything {@see TenantResource} carries, plus the operator
 * fields and the subscription in full.
 */
class TenantDetailResource extends TenantResource
{
    public function toArray(Request $request): array
    {
        return [
            ...parent::toArray($request),

            'archived_at' => $this->archived_at,
            'feature_overrides' => $this->feature_overrides ?? [],
            'max_branches_override' => $this->max_branches_override,
            'max_users_override' => $this->max_users_override,
            'admin_tags' => $this->admin_tags ?? [],
            'admin_follow_up' => (bool) $this->admin_follow_up,

            'subscription' => $this->when(
                $this->resource->relationLoaded('platformSubscription'),
                fn () => $this->platformSubscription === null
                    ? null
                    : new PlatformSubscriptionResource($this->platformSubscription),
            ),
        ];
    }
}

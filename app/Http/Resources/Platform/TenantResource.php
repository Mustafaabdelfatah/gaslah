<?php

namespace App\Http\Resources\Platform;

use App\Scopes\Tenancy\OrganizationScopes;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the platform tenant directory: identity, operator status, and the volume
 * figures attached by {@see OrganizationScopes::scopeWithTenantStats}.
 */
class TenantResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        // whenLoaded() collapses to null once a loaded relation is empty, which would hide
        // the grandfathered case, so the presence check is explicit here.
        $hasSubscriptionLoaded = $this->resource->relationLoaded('platformSubscription');

        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,

            'is_suspended' => (bool) $this->is_suspended,
            'is_archived' => $this->isArchived(),

            'branches_count' => $this->whenCounted('branches'),
            'orders_count' => $this->when($this->orders_count !== null, fn () => (int) $this->orders_count),
            'users_count' => $this->when($this->users_count !== null, fn () => (int) $this->users_count),
            'revenue' => $this->when($this->revenue !== null, fn () => round((float) $this->revenue, 2)),

            'status' => $this->when(
                $hasSubscriptionLoaded,
                fn () => $this->platformSubscription?->displayStatus() ?? 'grandfathered',
            ),
            'plan_name' => $this->when(
                $hasSubscriptionLoaded,
                fn () => $this->platformSubscription?->plan?->name,
            ),

            'created_at' => $this->created_at,
        ];
    }
}

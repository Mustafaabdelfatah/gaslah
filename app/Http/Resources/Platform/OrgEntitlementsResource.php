<?php

namespace App\Http\Resources\Platform;

use App\Enum\Platform\PlatformSubscriptionStatusEnum;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An organization's own entitlements snapshot.
 *
 * Any staff member may read it: it drives which features the UI hides and the status
 * banner it shows, so it carries no commercial detail beyond the plan's name.
 *
 * @property Organization $resource
 */
class OrgEntitlementsResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function __construct(Organization $resource, private readonly array $snapshot)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        $subscription = $this->resource->platformSubscription;

        return [
            ...$this->snapshot,

            'read_only' => ! $this->snapshot['active'],
            'suspended' => (bool) $this->resource->is_suspended,

            'status' => $subscription?->displayStatus() ?? 'grandfathered',
            'trial' => $subscription?->status === PlatformSubscriptionStatusEnum::Trial,
            'plan_name' => $subscription?->plan?->name,
            'current_period_end' => $subscription?->current_period_end,
        ];
    }
}

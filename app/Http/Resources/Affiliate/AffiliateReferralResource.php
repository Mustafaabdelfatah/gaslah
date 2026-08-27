<?php

namespace App\Http\Resources\Affiliate;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One referred tenant and the commission it earned.
 *
 * Only the referred business's name is exposed — an affiliate is owed a commission, not a
 * view into the tenant they introduced.
 */
class AffiliateReferralResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization' => $this->whenLoaded('organization', fn () => $this->organization?->name),

            'plan_name' => $this->plan_name,
            'sub_amount' => round((float) $this->sub_amount, 2),
            'commission' => round((float) $this->commission, 2),

            'status' => $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }
}

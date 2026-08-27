<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One subscription lifecycle event (feeds the MRR waterfall and the tenant timeline).
 */
class PlatformEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'organization_id' => $this->organization_id,
            'type' => $this->type,
            'plan_name' => $this->plan_name,
            'cycle' => $this->cycle,
            'monthly' => $this->monthly,
            'amount' => $this->amount,
            'created_at' => $this->created_at,
        ];
    }
}

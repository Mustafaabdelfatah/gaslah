<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A paid capability a tenant holds above its plan.
 */
class OrgAddonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'is_active' => (bool) $this->is_active,
            'price_monthly' => $this->price_monthly,

            'activated_at' => $this->activated_at,
            'expires_at' => $this->expires_at,

            // Switched on and not lapsed. Whether it actually grants anything also
            // depends on the subscription being live, which the entitlements say.
            'is_granting' => $this->isGranting(),
        ];
    }
}

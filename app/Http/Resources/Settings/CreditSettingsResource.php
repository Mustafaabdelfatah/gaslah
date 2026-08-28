<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's deferred-payment configuration.
 */
class CreditSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'is_enabled' => (bool) $this->is_enabled,
            'default_limit' => $this->default_limit,
        ];
    }
}

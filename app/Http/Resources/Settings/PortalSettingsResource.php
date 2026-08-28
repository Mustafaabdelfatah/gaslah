<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The customer portal's identity, joining the organization's own columns to the portal
 * block of its settings so the screen reads one shape rather than two stores.
 */
class PortalSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $portal = is_array($this->settings) ? ($this->settings['portal'] ?? []) : [];

        return [
            'logo_url' => $this->logo_url,
            'slug' => $this->slug,
            'custom_domain' => $this->custom_domain,
            'brand_primary' => $this->brand_primary,
            'brand_accent' => $this->brand_accent,

            'show_offers' => (bool) ($portal['show_offers'] ?? false),
            'terms_url' => $portal['terms_url'] ?? null,
            'privacy_url' => $portal['privacy_url'] ?? null,
        ];
    }
}

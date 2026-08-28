<?php

namespace App\Http\Resources\Settings;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The organization's commercial profile, as its settings screen shows it.
 */
class BusinessSettingsResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'default_currency' => $this->default_currency,
            'tax_rate' => $this->tax_rate,

            'phone' => $this->phone,
            'email' => $this->email,
            'address' => $this->address,

            'cr_number' => $this->cr_number,
            'vat_number' => $this->vat_number,

            'receipt_footer' => $this->receipt_footer,
            'receipt_width' => $this->receipt_width,

            'brand_primary' => $this->brand_primary,
            'brand_accent' => $this->brand_accent,
            'logo_url' => $this->logo_url,

            'slug' => $this->slug,
            'custom_domain' => $this->custom_domain,
        ];
    }
}

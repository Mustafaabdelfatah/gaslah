<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The tenant's delivery configuration: which methods it offers, what it charges, and the
 * hours it collects within.
 *
 * Every key is optional — the settings service merges what arrives over the stored
 * defaults, so a partial save adjusts one thing without resetting the rest.
 */
class DeliverySettingsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'methods' => ['nullable', 'array'],
            'methods.selfDelivery' => ['nullable', 'boolean'],
            'methods.platformDriver' => ['nullable', 'boolean'],
            'methods.integration' => ['nullable', 'boolean'],

            'self' => ['nullable', 'array'],
            'self.feeMode' => ['nullable', 'in:flat,per_direction'],
            'self.flatFee' => ['nullable', 'numeric', 'min:0'],
            'self.pickupFee' => ['nullable', 'numeric', 'min:0'],
            'self.deliveryFee' => ['nullable', 'numeric', 'min:0'],
            'self.hoursFrom' => ['nullable', 'date_format:H:i'],
            'self.hoursTo' => ['nullable', 'date_format:H:i'],
            'self.slotMinutes' => ['nullable', 'integer', 'min:15', 'max:480'],

            'workflow' => ['nullable', 'array'],
        ];
    }
}

<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\BaseFormRequest;

/**
 * A pickup or delivery the customer books themselves.
 *
 * A scheduled slot must be in the future — booking a collection for a time that has passed
 * is never what was meant.
 */
class StorePortalDeliveryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'in:pickup,delivery,both'],
            'address' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'order_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}

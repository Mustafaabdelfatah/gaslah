<?php

namespace App\Http\Requests\Delivery;

use App\Enum\Delivery\DeliveryStatusEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Assign a delivery — to one of the tenant's own drivers, or out to an external provider.
 *
 * Which of the two was meant, and whether the tenant is allowed that method at all, is the
 * request service's decision; this only guarantees the shape.
 */
class AssignDeliveryRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'driver_id' => ['nullable', 'integer'],
            'external_provider' => ['nullable', 'string', 'max:60'],
            'external_ref' => ['nullable', 'string', 'max:120'],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', new Enum(DeliveryStatusEnum::class)],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}

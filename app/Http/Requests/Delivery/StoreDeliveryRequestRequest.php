<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\BaseFormRequest;

class StoreDeliveryRequestRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'type' => ['required', 'in:pickup,delivery,both'],
            'order_id' => ['nullable', 'integer'],
            'zone_id' => ['nullable', 'integer'],
            'address' => ['required', 'string', 'max:1000'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'scheduled_at' => ['nullable', 'date'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ];
    }
}

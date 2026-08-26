<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\BaseFormRequest;

class DeliveryInventoryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.service_id' => ['required', 'integer'],
            'items.*.garment_type_id' => ['nullable', 'integer'],
            'items.*.is_express' => ['nullable', 'boolean'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

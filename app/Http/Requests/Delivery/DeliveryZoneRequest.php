<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\BaseFormRequest;

class DeliveryZoneRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:200'],
            'name_en' => ['nullable', 'string', 'max:200'],
            'fee' => [$required, 'numeric', 'min:0'],
            'eta_minutes' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

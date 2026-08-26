<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\BaseFormRequest;

class DriverRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:200'],
            'phone' => [$required, 'string', 'min:6', 'max:32', 'regex:/^[0-9+]+$/'],
            'vehicle' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}

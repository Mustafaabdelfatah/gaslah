<?php

namespace App\Http\Requests\Catalog;

use App\Enum\Catalog\CustomerTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

class CustomerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'phone' => ['required', 'string', 'min:6', 'max:32', 'regex:/^[0-9+]+$/'],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'birth_date' => ['nullable', 'date'],
            'type' => ['nullable', new Enum(CustomerTypeEnum::class)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'preferences' => ['nullable', 'array'],
        ];
    }
}

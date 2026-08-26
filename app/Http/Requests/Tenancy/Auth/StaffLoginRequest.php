<?php

namespace App\Http\Requests\Tenancy\Auth;

use App\Http\Requests\BaseFormRequest;

class StaffLoginRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'meta' => ['nullable', 'array'],
        ];
    }
}

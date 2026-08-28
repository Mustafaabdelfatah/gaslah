<?php

namespace App\Http\Requests\Market;

use App\Http\Requests\BaseFormRequest;

/**
 * A market supplier signing in to their portal.
 */
class SupplierLoginRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'max:100'],
        ];
    }

    public function email(): string
    {
        return $this->string('email')->toString();
    }

    public function password(): string
    {
        return $this->string('password')->toString();
    }
}

<?php

namespace App\Http\Requests\Driver;

use App\Http\Requests\BaseFormRequest;

/**
 * Ask for a sign-in code on the driver app. Drivers hold no password — the phone number
 * plus a code is the whole credential.
 */
class DriverOtpRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    public function phone(): string
    {
        return $this->string('phone')->toString();
    }
}

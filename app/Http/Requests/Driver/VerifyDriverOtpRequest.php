<?php

namespace App\Http\Requests\Driver;

/**
 * Exchange a driver sign-in code for a session.
 */
class VerifyDriverOtpRequest extends DriverOtpRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'max:12'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}

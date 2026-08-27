<?php

namespace App\Http\Requests\Portal;

/**
 * Exchange a portal sign-in code for a customer session.
 */
class VerifyPortalOtpRequest extends PortalOtpRequest
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

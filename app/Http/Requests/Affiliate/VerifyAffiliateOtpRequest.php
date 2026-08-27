<?php

namespace App\Http\Requests\Affiliate;

/**
 * Exchange an affiliate sign-in code for a session.
 */
class VerifyAffiliateOtpRequest extends AffiliateOtpRequest
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

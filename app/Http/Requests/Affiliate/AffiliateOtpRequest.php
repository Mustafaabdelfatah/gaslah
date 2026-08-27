<?php

namespace App\Http\Requests\Affiliate;

use App\Http\Requests\BaseFormRequest;

/**
 * Ask for a sign-in code on the affiliate panel.
 */
class AffiliateOtpRequest extends BaseFormRequest
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

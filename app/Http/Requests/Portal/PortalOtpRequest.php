<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\BaseFormRequest;

/**
 * Ask for a sign-in code on the customer portal.
 *
 * The organization is named because a phone number identifies a customer only within one
 * tenant — the same person may be a customer of several laundries.
 */
class PortalOtpRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'min:6', 'max:32'],
            'org' => ['required', 'string', 'max:120'],
        ];
    }

    public function phone(): string
    {
        return $this->string('phone')->toString();
    }

    public function org(): string
    {
        return $this->string('org')->toString();
    }
}

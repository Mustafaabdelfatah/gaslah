<?php

namespace App\Http\Requests\Affiliate;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * Sign up as an affiliate. The affiliate surface is platform-wide, so both the address and
 * the number are unique across it rather than per tenant.
 */
class RegisterAffiliateRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', Rule::unique('affiliates', 'email')],
            'phone' => ['required', 'string', 'min:6', 'max:32', Rule::unique('affiliates', 'phone')],
        ];
    }
}

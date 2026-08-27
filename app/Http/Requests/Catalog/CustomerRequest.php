<?php

namespace App\Http\Requests\Catalog;

use App\Enum\Catalog\CustomerTypeEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A customer of the laundry.
 *
 * The phone number identifies them at the counter and on the portal, so it is unique
 * inside the tenant — expressed as a rule here rather than as a hand-thrown check, which
 * makes it a field error the client can show beside the input.
 */
class CustomerRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'phone' => [
                'required',
                'string',
                'min:6',
                'max:32',
                'regex:/^[0-9+]+$/',
                Rule::unique('customers', 'phone')
                    ->where('organization_id', $this->organizationId())
                    ->ignore($this->route('customer')?->getKey()),
            ],
            'email' => ['nullable', 'email'],
            'address' => ['nullable', 'string', 'max:500'],
            'birth_date' => ['nullable', 'date'],
            'type' => ['nullable', new Enum(CustomerTypeEnum::class)],
            'credit_limit' => ['nullable', 'numeric', 'min:0'],
            'preferences' => ['nullable', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => __('api.phone_already_used'),
        ];
    }
}

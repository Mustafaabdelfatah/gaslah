<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * A delivery driver belonging to the tenant.
 *
 * The phone is unique system-wide rather than per tenant: it is the driver's whole
 * credential at sign-in, so it must resolve exactly one driver across the platform.
 */
class DriverRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:200'],
            'phone' => [
                $required,
                'string',
                'min:6',
                'max:32',
                'regex:/^[0-9+]+$/',
                Rule::unique('drivers', 'phone')->ignore($this->route('driver')?->getKey()),
            ],
            'vehicle' => ['nullable', 'string', 'max:200'],
            'user_id' => ['nullable', 'integer'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'phone.unique' => __('api.delivery_driver_phone_taken'),
        ];
    }
}

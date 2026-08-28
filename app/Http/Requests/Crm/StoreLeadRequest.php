<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A new prospective laundry. It always starts at the first stage, so the stage is not a
 * field here.
 */
class StoreLeadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'min:2', 'max:200'],
            'contact_name' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'city' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', 'string', 'max:60'],
            'expected_mrr' => ['nullable', 'numeric', 'min:0', 'max:10000000'],

            // Only a platform admin can own a lead. Scoped in the rule so assigning one
            // to a tenant's staff member is a 422 with a field name on it.
            'owner_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('is_platform_owner', true)],
        ];
    }
}

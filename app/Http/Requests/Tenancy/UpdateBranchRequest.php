<?php

namespace App\Http\Requests\Tenancy;

/**
 * Amend a branch. Only the supplied fields are touched, so closing one is a request
 * carrying `is_active` alone.
 */
class UpdateBranchRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

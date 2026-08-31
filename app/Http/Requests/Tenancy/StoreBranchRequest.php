<?php

namespace App\Http\Requests\Tenancy;

/**
 * Open a branch.
 *
 * Uniqueness of the code is settled by OrganizationService, not a scoped `unique` rule:
 * the answer has to be the same 422 whichever path reaches it.
 */
class StoreBranchRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'code' => ['nullable', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:500'],
            'phone' => ['nullable', 'string', 'max:40'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

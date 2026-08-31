<?php

namespace App\Http\Requests\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use Illuminate\Validation\Rule;

/**
 * Amend a staff member.
 *
 * The email is deliberately not editable: it is the account's identity and the thing
 * every audit trail points at. A `permissions` key that is present but null clears an
 * override; an absent key leaves it alone.
 */
class UpdateOrgUserRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:200'],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['nullable', 'string', 'min:6', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],

            'branches' => ['sometimes', 'array', 'min:1'],
            'branches.*.branch_id' => ['required_with:branches', 'integer'],
            'branches.*.role' => ['required_with:branches', Rule::in(StaffRoleEnum::values())],

            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(StaffPermissionEnum::values())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->only(['name', 'phone', 'is_active', 'branches']);

        if ($this->filled('password')) {
            $data['password'] = $this->input('password');
        }

        if ($this->has('permissions')) {
            $data['permissions'] = $this->input('permissions');
        }

        return $data;
    }
}

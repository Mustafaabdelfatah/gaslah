<?php

namespace App\Http\Requests\Tenancy;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use Illuminate\Validation\Rule;

/**
 * Hire a staff member.
 *
 * Which branches and roles the caller may actually hand out is not a shape question —
 * OrgUserService settles it against the caller's own rank and reach.
 */
class StoreOrgUserRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:200', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'phone' => ['nullable', 'string', 'max:30'],
            'password' => ['required', 'string', 'min:6', 'max:100'],
            'is_active' => ['nullable', 'boolean'],

            'branches' => ['required', 'array', 'min:1'],
            'branches.*.branch_id' => ['required', 'integer'],
            'branches.*.role' => ['required', Rule::in(StaffRoleEnum::values())],

            // Absent means "follow the role"; a list means an explicit override.
            'permissions' => ['nullable', 'array'],
            'permissions.*' => [Rule::in(StaffPermissionEnum::values())],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $data = $this->safe()->only(['name', 'email', 'phone', 'password', 'is_active', 'branches']);

        if ($this->has('permissions')) {
            $data['permissions'] = $this->input('permissions') ?? [];
        }

        return $data;
    }
}

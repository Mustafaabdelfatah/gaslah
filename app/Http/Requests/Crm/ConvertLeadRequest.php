<?php

namespace App\Http\Requests\Crm;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Turning a lead into a real tenant.
 *
 * The business name comes from the lead itself; what is needed here is the owner account
 * that will run it.
 */
class ConvertLeadRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'admin_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::min(8)],
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
        ];
    }

    /**
     * @return array{admin_name: string, email: string, password: string, plan_id: ?int}
     */
    public function owner(): array
    {
        return [
            'admin_name' => $this->string('admin_name')->trim()->toString(),
            'email' => $this->string('email')->trim()->toString(),
            'password' => $this->string('password')->toString(),
            'plan_id' => $this->filled('plan_id') ? (int) $this->input('plan_id') : null,
        ];
    }
}

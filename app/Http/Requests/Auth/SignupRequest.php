<?php

namespace App\Http\Requests\Auth;

use App\Http\Requests\BaseFormRequest;
use App\Rules\StrongPassword;
use Illuminate\Validation\Rule;

/**
 * Public self-service tenant signup.
 *
 * Whether signup is open at all is decided before validation runs (see
 * SignupController), so a closed signup cannot be used to probe which addresses exist
 * through the unique rule.
 */
class SignupRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'org_name' => ['required', 'string', 'min:2', 'max:200'],
            'admin_name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->withoutTrashed()],
            'phone' => ['nullable', 'string', 'max:32'],
            'password' => [
                'required',
                'string',
                'min:6',
                'max:100',
                config('project.auth.strong_password') ? new StrongPassword : '',
            ],
        ];
    }
}

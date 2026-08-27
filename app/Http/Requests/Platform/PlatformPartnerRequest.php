<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * A founding partner's details.
 *
 * The percentage is bounded here only as a shape check; whether it fits under the total
 * ownership ceiling is a question about the other partners, so the service settles it
 * under a lock.
 */
class PlatformPartnerRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->route('partner') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'role' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'ownership_percent' => [$required, 'numeric', 'min:0', 'max:100'],
            'joined_at' => ['nullable', 'date'],
            'is_active' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ];
    }
}

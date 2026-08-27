<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * Hard suspension of a tenant, independent of its subscription state.
 */
class SuspendTenantRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'suspended' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function isSuspending(): bool
    {
        return $this->booleanInput('suspended');
    }

    public function reason(): ?string
    {
        return $this->input('reason');
    }
}

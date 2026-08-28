<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * Taking a tenant out of circulation. The reason is optional but ends up on the audit
 * entry, which is the only place anyone will later look to find out why.
 */
class ArchiveTenantRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        return $this->input('reason');
    }
}

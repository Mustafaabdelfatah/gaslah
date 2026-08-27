<?php

namespace App\Http\Requests\Messaging;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A tenant's own announcement, shown to its customers in the portal.
 */
class OrgAnnouncementRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $required = $this->route('announcement') !== null ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'min:2', 'max:200'],
            'body' => [$required, 'string', 'max:1000'],
            'image_url' => ['nullable', 'string', 'regex:#^(https?://|/)#', 'max:500'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

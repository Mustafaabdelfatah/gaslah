<?php

namespace App\Http\Requests\Settings;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Whether the organization sells on account, and the limit a new customer starts with.
 */
class UpdateCreditSettingsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            // Matched to the column's own CHECK, so the same ceiling is stated once in the
            // schema and once where the user is told about it.
            'default_limit' => ['required', 'numeric', 'min:0', 'max:10000000'],
        ];
    }

    /**
     * @return array{is_enabled: bool, default_limit: float}
     */
    public function settings(): array
    {
        return [
            'is_enabled' => $this->boolean('is_enabled'),
            'default_limit' => (float) $this->input('default_limit'),
        ];
    }
}

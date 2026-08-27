<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * How long the sweeper waits before advancing an aged order to ready, per service speed.
 */
class AutomationSettingsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'delays' => ['nullable', 'array'],
            'delays.default.normal' => ['nullable', 'integer', 'min:1'],
            'delays.default.express' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function isEnabled(): bool
    {
        return $this->booleanInput('enabled');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function delays(): ?array
    {
        return $this->input('delays');
    }
}

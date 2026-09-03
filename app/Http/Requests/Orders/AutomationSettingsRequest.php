<?php

namespace App\Http\Requests\Orders;

use App\Enum\Catalog\ServiceTypeEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * How long the sweeper waits before advancing an aged order to ready, per service speed.
 */
class AutomationSettingsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $serviceTypes = implode(',', ServiceTypeEnum::values());

        return [
            'enabled' => ['required', 'boolean'],
            'delays' => ['nullable', 'array:default,service_types'],
            'delays.default' => ['nullable', 'array:normal,express'],
            'delays.default.normal' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delays.default.express' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delays.service_types' => ['nullable', "array:{$serviceTypes}"],
            'delays.service_types.*' => ['nullable', 'array:normal,express'],
            'delays.service_types.*.normal' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delays.service_types.*.express' => ['nullable', 'integer', 'min:0', 'max:100000'],
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
        return $this->validated('delays');
    }
}

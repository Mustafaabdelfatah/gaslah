<?php

namespace App\Http\Requests\Zatca;

use App\Http\Requests\Tenancy\TenantFormRequest;

class SubmitZatcaComplianceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'otp' => ['required', 'string', 'min:4', 'max:12', 'regex:/^\d+$/'],
        ];
    }

    public function otp(): string
    {
        return trim((string) $this->validated('otp'));
    }
}

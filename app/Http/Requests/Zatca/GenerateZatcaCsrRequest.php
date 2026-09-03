<?php

namespace App\Http\Requests\Zatca;

use App\Http\Requests\Tenancy\TenantFormRequest;

class GenerateZatcaCsrRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function force(): bool
    {
        return $this->boolean('force');
    }
}

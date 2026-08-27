<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Clear (or un-clear) every ledger line in the reconciliation at once.
 */
class ClearAllBankLinesRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'cleared' => ['required', 'boolean'],
        ];
    }

    public function isCleared(): bool
    {
        return $this->booleanInput('cleared');
    }
}

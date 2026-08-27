<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Mark one ledger line as cleared against the bank statement, or un-clear it.
 */
class ClearBankLineRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'line_id' => ['required', 'integer'],
            'cleared' => ['required', 'boolean'],
        ];
    }

    public function lineId(): int
    {
        return $this->integer('line_id');
    }

    public function isCleared(): bool
    {
        return $this->booleanInput('cleared');
    }
}

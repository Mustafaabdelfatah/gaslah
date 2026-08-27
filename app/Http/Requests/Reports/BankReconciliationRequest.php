<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * How much of the bank ledger to pull into the reconciliation view.
 */
class BankReconciliationRequest extends TenantFormRequest
{
    private const DEFAULT_LIMIT = 500;

    public function rules(): array
    {
        return [
            'limit' => ['nullable', 'integer', 'min:1', 'max:2000'],
        ];
    }

    public function limit(): int
    {
        return $this->filled('limit') ? $this->integer('limit') : self::DEFAULT_LIMIT;
    }
}

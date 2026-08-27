<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The closing balance shown on the bank statement, which the cleared ledger lines are
 * reconciled against. It may be negative — an overdrawn account is still a real balance.
 */
class StatementBalanceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'balance' => ['required', 'numeric'],
        ];
    }

    public function balance(): float
    {
        return (float) $this->input('balance');
    }
}

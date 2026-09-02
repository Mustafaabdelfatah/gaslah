<?php

namespace App\Http\Requests\Catalog;

use App\Enum\Accounting\SystemAccountEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Top up a customer's wallet at the counter.
 *
 * The funding method decides which asset account the money lands in, so it is resolved
 * here where its allowed values are declared.
 */
class WalletTopupRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000'],
            'method' => ['required', 'in:cash,card,transfer'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function amount(): float
    {
        return (float) $this->input('amount');
    }

    public function fundingAccount(): SystemAccountEnum
    {
        return $this->input('method') === 'cash'
            ? SystemAccountEnum::Cash
            : SystemAccountEnum::Bank;
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}

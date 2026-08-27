<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * A cash payout to a partner. Dated today unless the operator is recording one that
 * already happened.
 */
class StoreDistributionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'date' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function amount(): float
    {
        return (float) $this->input('amount');
    }

    /**
     * Not named date(): Illuminate\Http\Request already declares one with a different
     * signature, and overriding it is a fatal error.
     */
    public function paidOn(): ?string
    {
        return $this->input('date');
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}

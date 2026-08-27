<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * Override the fee on a payout batch before it is sent.
 */
class UpdatePayoutFeeRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'fee' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function fee(): float
    {
        return (float) $this->input('fee');
    }
}

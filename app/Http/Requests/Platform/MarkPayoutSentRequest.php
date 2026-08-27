<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * Record that the bank transfer for a payout batch has left. The reference is required —
 * it is the only evidence tying the batch to the money that moved.
 */
class MarkPayoutSentRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'transfer_ref' => ['required', 'string', 'max:120'],
        ];
    }

    public function transferRef(): string
    {
        return $this->string('transfer_ref')->toString();
    }
}

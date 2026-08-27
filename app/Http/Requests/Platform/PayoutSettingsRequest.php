<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * The operator's payout policy: pricing, the minimum worth transferring, how many
 * approvals a batch needs, and which weekdays the scheduled draw runs.
 */
class PayoutSettingsRequest extends BaseFormRequest
{
    private const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function rules(): array
    {
        return [
            'fee_fixed' => ['nullable', 'numeric', 'min:0'],
            'fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'required_approvals' => ['nullable', 'integer', 'min:1', 'max:5'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['in:'.implode(',', self::WEEKDAYS)],
        ];
    }
}

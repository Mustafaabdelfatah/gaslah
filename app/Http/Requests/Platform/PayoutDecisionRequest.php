<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * The free-text that accompanies a maker-checker decision on a payout batch — a note when
 * approving, a reason when rejecting or cancelling. Both are optional and share one shape,
 * so the three endpoints do not each carry their own near-identical rule set.
 */
class PayoutDecisionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'note' => ['nullable', 'string', 'max:500'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        return $this->input('note');
    }

    public function reason(): ?string
    {
        return $this->input('reason');
    }
}

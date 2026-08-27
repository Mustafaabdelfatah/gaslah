<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use App\Models\Organization;
use Illuminate\Validation\Rule;

/**
 * Open a payout batch for one tenant. Omitting the fee lets the configured pricing decide.
 */
class StorePayoutBatchRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'organization_id' => ['required', 'integer', Rule::exists('organizations', 'id')],
            'fee' => ['nullable', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function organization(): Organization
    {
        return Organization::query()->findOrFail($this->integer('organization_id'));
    }

    public function fee(): ?float
    {
        return $this->filled('fee') ? (float) $this->input('fee') : null;
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}

<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Close a till shift with the cash actually counted. The variance against what the shift
 * expected is the service's to compute.
 */
class CloseShiftRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function actualCash(): float
    {
        return (float) $this->input('actual_cash');
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}

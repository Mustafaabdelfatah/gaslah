<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Only the planned figure and its note are editable — moving a line to another
 * category or month is a different line, created through the store endpoint.
 */
class UpdateBudgetRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'gt:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

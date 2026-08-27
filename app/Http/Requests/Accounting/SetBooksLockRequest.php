<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Move (or clear) the accounting period lock. A null date reopens the books.
 */
class SetBooksLockRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'closed_through' => ['nullable', 'date'],
        ];
    }

    public function closedThrough(): ?string
    {
        return $this->input('closed_through');
    }
}

<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A planned spend for one category in one month. Re-sending the same scope edits
 * the existing line rather than adding a second one, so there is no uniqueness
 * rule here — the service upserts.
 */
class StoreBudgetRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            // Null plans the whole organization rather than a single branch.
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('organization_id', $this->organizationId()),
            ],
            'category' => ['required', new Enum(ExpenseCategoryEnum::class)],
            'month' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A supplier bill accrued against Accounts Payable.
 */
class StorePayableRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('organization_id', $this->organizationId()),
            ],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'category' => ['required', new Enum(ExpenseCategoryEnum::class)],
            'issue_date' => ['nullable', 'date'],
            'due_date' => ['required', 'date'],
            'bill_no' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}

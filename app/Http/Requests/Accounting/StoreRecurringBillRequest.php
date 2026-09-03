<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Enum\Accounting\RecurringFrequencyEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

/**
 * A reusable AP-bill or directly-paid expense schedule.
 */
class StoreRecurringBillRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'category' => ['required', new Enum(ExpenseCategoryEnum::class)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:100000000'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'supplier_id' => [
                'nullable',
                'integer',
                Rule::exists('suppliers', 'id')->where('organization_id', $this->organizationId()),
            ],
            'branch_id' => [
                'nullable',
                'integer',
                Rule::exists('branches', 'id')->where('organization_id', $this->organizationId()),
            ],
            'paid_from' => ['required', new Enum(ExpensePaidFromEnum::class)],
            'frequency' => ['required', new Enum(RecurringFrequencyEnum::class)],
            'anchor_day' => ['nullable', 'integer', 'between:1,31'],
            'due_days' => ['nullable', 'integer', 'between:0,180'],
            'start_date' => ['nullable', 'date'],
            'description' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

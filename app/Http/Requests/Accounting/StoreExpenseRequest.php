<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\ExpenseCategoryEnum;
use App\Enum\Accounting\ExpensePaidFromEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreExpenseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'category' => ['required', new Enum(ExpenseCategoryEnum::class)],
            'amount' => ['required', 'numeric', 'gt:0'],
            'vat_amount' => ['nullable', 'numeric', 'min:0', 'lte:amount'],
            'paid_from' => ['nullable', new Enum(ExpensePaidFromEnum::class)],
            'description' => ['nullable', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:100'],
            'branch_id' => ['nullable', 'integer'],
        ];
    }
}

<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\BaseFormRequest;

class StoreJournalRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'memo' => ['nullable', 'string', 'max:255'],
            'branch_id' => ['nullable', 'integer'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ];
    }
}

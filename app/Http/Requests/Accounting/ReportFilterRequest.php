<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\BaseFormRequest;

class ReportFilterRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'integer'],
            'as_of' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array{from: string|null, to: string|null, branch_id: int|null}
     */
    public function toFilter(): array
    {
        return [
            'from' => $this->input('from'),
            'to' => $this->input('to'),
            'branch_id' => $this->input('branch_id') ? (int) $this->input('branch_id') : null,
        ];
    }
}

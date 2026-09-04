<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\BaseFormRequest;

class MarkOrdersReadyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['required', 'integer', 'distinct'],
        ];
    }

    /** @return array<int, int> */
    public function ids(): array
    {
        return array_map('intval', $this->validated('ids'));
    }
}

<?php

namespace App\Http\Requests\Driver;

use App\Http\Requests\BaseFormRequest;

/**
 * A driver declining an assigned job, optionally saying why.
 */
class RejectDeliveryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function reason(): ?string
    {
        return $this->input('reason');
    }
}

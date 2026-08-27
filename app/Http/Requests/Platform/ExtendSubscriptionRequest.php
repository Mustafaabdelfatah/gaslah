<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * Grant a tenant more subscription time.
 */
class ExtendSubscriptionRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:3650'],
        ];
    }

    public function days(): int
    {
        return $this->integer('days');
    }
}

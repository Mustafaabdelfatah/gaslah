<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * The operator's dunning policy: when to remind before renewal, how often to chase while
 * overdue, how long the grace period runs, and over which channels.
 */
class DunningPolicyRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],

            'remind_days_before' => ['nullable', 'array', 'max:10'],
            'remind_days_before.*' => ['integer', 'min:1', 'max:365'],

            'remind_days_after' => ['nullable', 'array', 'max:10'],
            'remind_days_after.*' => ['integer', 'min:1', 'max:365'],

            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],

            'channels' => ['nullable', 'array'],
            'channels.whatsapp' => ['nullable', 'boolean'],
            'channels.email' => ['nullable', 'boolean'],
        ];
    }
}

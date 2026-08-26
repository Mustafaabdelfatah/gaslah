<?php

namespace App\Http\Requests\Subscriptions;

use App\Enum\Subscriptions\SubscriptionCycleEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

class SubscriptionPlanRequest extends BaseFormRequest
{
    public function rules(): array
    {
        // On update only the supplied fields are validated and persisted.
        $required = $this->isMethod('post') ? 'required' : 'sometimes';

        return [
            'name' => [$required, 'string', 'min:1', 'max:200'],
            'cycle' => [$required, new Enum(SubscriptionCycleEnum::class)],
            'type' => [$required, new Enum(SubscriptionTypeEnum::class)],
            'price' => [$required, 'numeric', 'min:0'],
            'quota' => ['nullable', 'numeric', 'min:0'],
            'service_id' => ['nullable', 'integer'],
            'auto_renew' => ['nullable', 'boolean'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

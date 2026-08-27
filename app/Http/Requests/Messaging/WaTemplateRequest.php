<?php

namespace App\Http\Requests\Messaging;

use App\Enum\Messaging\WaCategoryEnum;
use App\Enum\Messaging\WaEventEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * A WhatsApp message template. The category decides whether the commercial gate lets it
 * out, so it is never free text.
 */
class WaTemplateRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $required = $this->route('template') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:1', 'max:120'],
            'category' => [$required, new Enum(WaCategoryEnum::class)],
            'event_key' => ['nullable', new Enum(WaEventEnum::class)],
            'body' => [$required, 'string', 'max:2000'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

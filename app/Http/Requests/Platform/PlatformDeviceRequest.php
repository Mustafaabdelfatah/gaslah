<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A device in the platform's hardware catalogue. The price is VAT-inclusive.
 */
class PlatformDeviceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        $required = $this->route('device') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'sku' => [
                'nullable',
                'string',
                'max:80',
                Rule::unique('platform_devices', 'sku')->ignore($this->route('device')?->getKey()),
            ],
            'price' => [$required, 'numeric', 'min:0', 'max:1000000'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer', 'min:0'],
        ];
    }
}

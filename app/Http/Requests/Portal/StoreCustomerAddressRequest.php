<?php

namespace App\Http\Requests\Portal;

use App\Http\Requests\BaseFormRequest;

/**
 * An address the customer saves on the portal for pickups and deliveries.
 */
class StoreCustomerAddressRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:40'],
            'district' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:200'],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function shouldBecomeDefault(): bool
    {
        return $this->booleanInput('is_default');
    }
}

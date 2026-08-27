<?php

namespace App\Http\Requests\Inventory;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A supplier the laundry buys from.
 */
class SupplierRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $required = $this->route('supplier') !== null ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'min:2', 'max:200'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
        ];
    }
}

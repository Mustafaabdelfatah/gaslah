<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A price cell of the catalogue grid. Only price and availability are editable — a cell
 * is never deleted, so past orders keep resolving the service they were sold under.
 */
class UpdateServiceRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'base_price' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'express_surcharge' => ['sometimes', 'numeric', 'min:0', 'max:1000000'],
            'is_express_available' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

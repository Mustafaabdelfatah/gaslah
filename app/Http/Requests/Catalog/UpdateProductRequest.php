<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Rename or retire a product. Prices live on its service cells, not here.
 */
class UpdateProductRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}

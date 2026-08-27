<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A catalogue category (the top level of the category × product × service-type grid).
 */
class ServiceCategoryRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:255'],
        ];
    }
}

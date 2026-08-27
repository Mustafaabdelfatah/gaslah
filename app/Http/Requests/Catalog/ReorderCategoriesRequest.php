<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The new display order of the catalogue categories, as the ids in the order they should
 * appear.
 */
class ReorderCategoriesRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer'],
        ];
    }

    /**
     * @return array<int, int>
     */
    public function ids(): array
    {
        return array_map('intval', $this->input('ids', []));
    }
}

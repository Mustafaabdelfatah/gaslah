<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\BaseFormRequest;

class StoreProductRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'category_id' => ['required', 'integer'],
            'name' => ['required', 'string', 'max:150'],
            'name_en' => ['nullable', 'string', 'max:150'],
            'icon' => ['nullable', 'string', 'max:255'],
            'code' => ['nullable', 'string', 'max:100'],
            'cells' => ['required', 'array', 'min:1'],
            'cells.*.base_price' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'cells.*.express_surcharge' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'cells.*.is_express_available' => ['nullable', 'boolean'],
        ];
    }
}

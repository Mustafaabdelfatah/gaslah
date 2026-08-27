<?php

namespace App\Http\Requests\Catalog;

use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * Set a product's code — the barcode-facing identifier, which must be unique inside the
 * tenant. The uniqueness is expressed as a rule so it fails as a 422 field error like any
 * other, rather than as a hand-thrown abort.
 */
class UpdateProductCodeRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'code' => [
                'nullable',
                'string',
                'max:100',
                Rule::unique('products', 'code')
                    ->where('organization_id', $this->organizationId())
                    ->ignore($this->route('product')?->getKey()),
            ],
        ];
    }
}

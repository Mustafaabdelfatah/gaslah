<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Dispose of a fixed asset. Proceeds may be nil — an asset can be scrapped as well as
 * sold — and the gain or loss against its book value is the service's to post.
 */
class DisposeAssetRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'proceeds' => ['nullable', 'numeric', 'min:0'],
            'via' => ['nullable', 'in:cash,bank'],
            'date' => ['nullable', 'date'],
        ];
    }
}

<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Open a till shift with the float counted into the drawer.
 */
class OpenShiftRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'opening_cash' => ['required', 'numeric', 'min:0'],
        ];
    }

    public function openingCash(): float
    {
        return (float) $this->input('opening_cash');
    }
}

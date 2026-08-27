<?php

namespace App\Http\Requests\Reports;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The reporting window shared by every report and analytics endpoint.
 *
 * Both ends are optional — the range service supplies the tenant's default window and
 * clamps anything unreasonable — so this only guarantees they are dates and in order.
 */
class DateRangeRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ];
    }

    public function from(): ?string
    {
        return $this->input('from');
    }

    public function to(): ?string
    {
        return $this->input('to');
    }
}

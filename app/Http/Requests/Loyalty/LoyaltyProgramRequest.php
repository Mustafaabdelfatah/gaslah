<?php

namespace App\Http\Requests\Loyalty;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The tenant's single loyalty programme: how fast points are earned and what one is worth
 * when redeemed.
 */
class LoyaltyProgramRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'earn_rate' => ['required', 'numeric', 'min:0', 'max:10000'],
            'point_value' => ['required', 'numeric', 'min:0', 'max:10000'],
            'expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}

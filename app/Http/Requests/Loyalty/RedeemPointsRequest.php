<?php

namespace App\Http\Requests\Loyalty;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Redeem points into wallet credit. Whether the customer holds enough is the loyalty
 * service's call, since it reads the balance under a lock.
 */
class RedeemPointsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'points' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    public function points(): float
    {
        return (float) $this->input('points');
    }
}

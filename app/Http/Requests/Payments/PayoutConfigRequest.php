<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * The tenant's payout schedule and receiving bank account.
 *
 * At most two draw days: a payout run is an operational commitment for the platform, not
 * something a tenant can schedule daily.
 */
class PayoutConfigRequest extends TenantFormRequest
{
    private const WEEKDAYS = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'];

    public function rules(): array
    {
        return [
            'days' => ['nullable', 'array', 'max:2'],
            'days.*' => ['in:'.implode(',', self::WEEKDAYS)],

            'bank' => ['nullable', 'array'],
            'bank.iban' => ['nullable', 'regex:/^SA\d{22}$/'],
            'bank.bank_name' => ['nullable', 'string', 'max:120'],
            'bank.beneficiary' => ['nullable', 'string', 'max:200'],
        ];
    }
}

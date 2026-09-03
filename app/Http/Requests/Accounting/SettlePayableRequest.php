<?php

namespace App\Http\Requests\Accounting;

use App\Enum\Accounting\PayableSettlementMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * The cash or bank account used for one supplier-bill settlement.
 */
class SettlePayableRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'via' => ['required', new Enum(PayableSettlementMethodEnum::class)],
            'date' => ['nullable', 'date'],
        ];
    }
}

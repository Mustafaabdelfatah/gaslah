<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * An operating cost of the platform.
 *
 * Naming a partner as the payer records that they fronted the money personally, which the
 * platform then owes back — so the partner must be one that actually exists.
 */
class PlatformExpenseRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['nullable', 'date'],
            'category' => ['required', 'string', 'max:80'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:10000000'],
            'note' => ['nullable', 'string', 'max:500'],
            'paid_by_partner_id' => ['nullable', 'integer', Rule::exists('platform_partners', 'id')],
        ];
    }
}

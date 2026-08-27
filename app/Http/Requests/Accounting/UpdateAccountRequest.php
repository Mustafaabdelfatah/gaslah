<?php

namespace App\Http\Requests\Accounting;

use App\Http\Requests\Tenancy\TenantFormRequest;
use App\Models\Account;

/**
 * Edit a chart-of-accounts entry.
 *
 * A system account's structure is frozen — the ledger and every report resolve it by its
 * system key — so only its display names are editable. Dropping is_active from the rules
 * for such an account means an attempt to deactivate one is ignored rather than honoured.
 */
class UpdateAccountRequest extends TenantFormRequest
{
    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'min:2', 'max:120'],
            'name_en' => ['nullable', 'string', 'max:120'],
        ];

        if (! $this->account()?->isStructurallyLocked()) {
            $rules['is_active'] = ['sometimes', 'boolean'];
        }

        return $rules;
    }

    private function account(): ?Account
    {
        $account = $this->route('account');

        return $account instanceof Account ? $account : null;
    }
}

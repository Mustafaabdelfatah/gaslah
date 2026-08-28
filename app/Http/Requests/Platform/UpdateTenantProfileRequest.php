<?php

namespace App\Http\Requests\Platform;

use App\Http\Requests\BaseFormRequest;

/**
 * An operator correcting a tenant's commercial details on its behalf — typically during
 * onboarding, or when a tax number turns out to be wrong on issued invoices.
 *
 * The tax rate is here because it varies by region and the operator is the one who knows
 * which applies.
 */
class UpdateTenantProfileRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:1', 'max:200'],
            'tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],

            'phone' => ['nullable', 'string', 'max:40'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'cr_number' => ['nullable', 'string', 'max:40'],
            'vat_number' => ['nullable', 'string', 'max:40'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profile(): array
    {
        $validated = $this->validated();

        foreach (['phone', 'email', 'address', 'cr_number', 'vat_number'] as $field) {
            $trimmed = trim((string) ($validated[$field] ?? ''));
            $validated[$field] = $trimmed === '' ? null : $trimmed;
        }

        return $validated;
    }
}

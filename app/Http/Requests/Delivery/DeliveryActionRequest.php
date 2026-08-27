<?php

namespace App\Http\Requests\Delivery;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A counter-side action on a delivery: record the driver's arrival, or decide whether the
 * customer must approve the invoice before the job completes.
 */
class DeliveryActionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'action' => ['required', 'in:arrive,require_invoice_approval'],
            'require' => ['nullable', 'boolean'],
        ];
    }

    public function action(): string
    {
        return $this->string('action')->toString();
    }

    /**
     * Defaults to true: asking for the approval switch without saying which way means
     * turning it on.
     */
    public function requiresApproval(): bool
    {
        return $this->booleanInput('require', true);
    }
}

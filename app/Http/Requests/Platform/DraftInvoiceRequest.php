<?php

namespace App\Http\Requests\Platform;

use App\Enum\Platform\InvoicePaymentMethodEnum;
use App\Enum\Platform\PlatformCycleEnum;
use App\Http\Requests\BaseFormRequest;
use App\Models\PlatformPlan;
use Illuminate\Validation\Rule;

/**
 * Draft a subscription invoice for a tenant.
 *
 * Plan, cycle and amount may all be omitted — the invoicer then falls back to the
 * tenant's own subscription. The payment method decides which reference fields the
 * accountant must supply.
 */
class DraftInvoiceRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => ['nullable', 'integer', Rule::exists('platform_plans', 'id')],
            'cycle' => ['nullable', Rule::in(PlatformCycleEnum::values())],
            'amount' => ['nullable', 'numeric', 'min:0'],

            'payment_method' => ['required', Rule::in(InvoicePaymentMethodEnum::values())],
            'bank_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::BankTransfer->value],
            'transfer_ref' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::BankTransfer->value],
            'gateway_name' => ['nullable', 'string', 'max:255', 'required_if:payment_method,'.InvoicePaymentMethodEnum::Gateway->value],
        ];
    }

    public function plan(): ?PlatformPlan
    {
        return $this->filled('plan_id') ? PlatformPlan::query()->find($this->integer('plan_id')) : null;
    }

    public function cycle(): ?PlatformCycleEnum
    {
        return $this->filled('cycle') ? PlatformCycleEnum::from($this->string('cycle')->toString()) : null;
    }

    public function amount(): ?float
    {
        return $this->filled('amount') ? (float) $this->input('amount') : null;
    }

    public function paymentMethod(): InvoicePaymentMethodEnum
    {
        return InvoicePaymentMethodEnum::from($this->string('payment_method')->toString());
    }

    /**
     * @return array{bank_name: string|null, transfer_ref: string|null, gateway_name: string|null}
     */
    public function paymentMeta(): array
    {
        return [
            'bank_name' => $this->input('bank_name'),
            'transfer_ref' => $this->input('transfer_ref'),
            'gateway_name' => $this->input('gateway_name'),
        ];
    }
}

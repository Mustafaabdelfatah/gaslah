<?php

namespace App\Http\Requests\Orders;

use App\Enum\Payments\PaymentMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * Collecting on an existing order at the counter.
 *
 * Only the methods that need no server-side verification are accepted: cash is in the
 * drawer, a card at the terminal and a transfer are receipts the cashier is holding.
 * Wallet and gateway money have their own verified paths.
 */
class CollectOrderPaymentRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'method' => ['required', Rule::in([
                PaymentMethodEnum::Cash->value,
                PaymentMethodEnum::Card->value,
                PaymentMethodEnum::Transfer->value,
            ])],
            'reference' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function amount(): float
    {
        return round((float) $this->input('amount'), 2);
    }

    public function method(): PaymentMethodEnum
    {
        return PaymentMethodEnum::from($this->string('method')->toString());
    }

    public function reference(): ?string
    {
        return $this->filled('reference') ? $this->string('reference')->trim()->toString() : null;
    }
}

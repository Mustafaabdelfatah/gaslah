<?php

namespace App\Http\Requests\Orders;

use App\Enum\Payments\PaymentMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

/**
 * Collecting on an existing order at the counter.
 *
 * Counter methods are accepted directly. Wallet collection additionally carries a
 * one-shot customer OTP proof; deferred changes the debt marker without inventing a
 * payment row.
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
                PaymentMethodEnum::Wallet->value,
                PaymentMethodEnum::Deferred->value,
            ])],
            'reference' => ['nullable', 'string', 'max:200'],
            'otp_token' => ['nullable', 'string'],
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

    public function otpToken(): ?string
    {
        return $this->input('otp_token');
    }
}

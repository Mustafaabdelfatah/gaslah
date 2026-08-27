<?php

namespace App\Http\Requests\Subscriptions;

use App\Enum\Payments\PaymentMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Settle a customer package.
 *
 * A wallet payment must carry the one-shot OTP proof; whether the proof is valid and
 * unburned is the payment path's decision, not a shape check.
 */
class PaySubscriptionRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'method' => ['required', 'in:cash,card,transfer,wallet'],
            'otp_token' => ['nullable', 'string'],
        ];
    }

    public function method(): PaymentMethodEnum
    {
        return PaymentMethodEnum::from($this->string('method')->toString());
    }

    public function otpToken(): ?string
    {
        return $this->input('otp_token');
    }
}

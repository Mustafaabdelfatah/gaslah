<?php

namespace App\Http\Requests\Payments;

use App\Http\Requests\BaseFormRequest;

/**
 * Pay an order through the public payment link.
 *
 * The amount is advisory: the payment service recomputes what is actually owed
 * server-side, so a tampered figure changes nothing.
 */
class PayOrderRequest extends BaseFormRequest
{
    private const DEFAULT_CHANNEL = 'card';

    public function rules(): array
    {
        return [
            'channel' => ['nullable', 'in:mada,card,stcpay,applepay'],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000'],
            'payment_ref' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function channel(): string
    {
        return $this->filled('channel') ? $this->string('channel')->toString() : self::DEFAULT_CHANNEL;
    }

    public function amount(): ?float
    {
        return $this->filled('amount') ? (float) $this->input('amount') : null;
    }

    public function paymentRef(): ?string
    {
        return $this->input('payment_ref');
    }
}

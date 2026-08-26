<?php

namespace App\Http\Requests\Orders;

use App\Enum\Orders\OrderPriorityEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreOrderRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
            'priority' => ['nullable', new Enum(OrderPriorityEnum::class)],
            'due_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'client_request_id' => ['nullable', 'string', 'max:80'],
            'express_surcharge' => ['nullable', 'numeric', 'min:0'],

            'discount' => ['nullable', 'array'],
            'discount.type' => ['required_with:discount', 'in:percent,fixed'],
            'discount.value' => ['required_with:discount', 'numeric', 'min:0'],

            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.service_id' => ['required', 'integer'],
            'items.*.garment_type_id' => ['nullable', 'integer'],
            'items.*.is_express' => ['nullable', 'boolean'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0', 'max:100000'],
            'items.*.notes' => ['nullable', 'string', 'max:500'],

            'payment' => ['nullable', 'array'],
            'payment.method' => ['required_with:payment', 'in:cash,card,transfer,wallet,deferred'],
            'payment.amount' => ['nullable', 'numeric', 'min:0'],
            'payment.verify_mode' => ['nullable', 'in:manual,terminal'],
            'payment.reference' => ['nullable', 'string', 'max:255'],
            'payment.otp_token' => ['nullable', 'string'],
            'payment.overpay_to' => ['nullable', 'in:change,wallet'],
            'payment.secondary' => ['nullable', 'array'],
            'payment.secondary.method' => ['required_with:payment.secondary', 'in:cash,card,transfer'],
            'payment.secondary.verify_mode' => ['nullable', 'in:manual,terminal'],
            'payment.secondary.reference' => ['nullable', 'string', 'max:255'],
        ];
    }
}

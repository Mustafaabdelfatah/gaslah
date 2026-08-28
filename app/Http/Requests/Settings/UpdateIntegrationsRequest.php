<?php

namespace App\Http\Requests\Settings;

use App\Enum\Payments\PaymentMethodEnum;
use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A tenant's integration settings.
 *
 * Every field is optional: the screen saves one section at a time, and the service treats
 * an absent key as "unchanged". The secrets are validated only for shape — a blank one is
 * a legitimate value here, meaning "keep the stored credential".
 */
class UpdateIntegrationsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'payment' => ['nullable', 'array'],
            'payment.methods' => ['nullable', 'array'],
            ...$this->paymentMethodRules(),
            'payment.gateway' => ['nullable', 'array'],
            'payment.gateway.provider' => ['nullable', 'in:stub,moyasar,hyperpay'],
            'payment.gateway.public_key' => ['nullable', 'string', 'max:500'],
            'payment.gateway.secret_key' => ['nullable', 'string', 'max:500'],

            'messaging' => ['nullable', 'array'],
            'messaging.enabled' => ['nullable', 'boolean'],

            'messaging.whatsapp' => ['nullable', 'array'],
            'messaging.whatsapp.enabled' => ['nullable', 'boolean'],
            'messaging.whatsapp.mode' => ['nullable', 'in:platform,custom'],
            'messaging.whatsapp.token' => ['nullable', 'string', 'max:1000'],
            'messaging.whatsapp.phone_id' => ['nullable', 'string', 'max:120'],

            'messaging.sms' => ['nullable', 'array'],
            'messaging.sms.enabled' => ['nullable', 'boolean'],
            'messaging.sms.provider' => ['nullable', 'string', 'max:40'],
            'messaging.sms.api_key' => ['nullable', 'string', 'max:500'],
            'messaging.sms.sender' => ['nullable', 'string', 'max:40'],

            'messaging.events' => ['nullable', 'array'],
            'messaging.events.*' => ['boolean'],

            'messaging.templates' => ['nullable', 'array'],
            'messaging.templates.*' => ['nullable', 'string', 'max:2000'],
        ];
    }

    /**
     * One boolean rule per payment method the application knows about, so a method that
     * does not exist cannot be switched on.
     *
     * @return array<string, array<int, string>>
     */
    private function paymentMethodRules(): array
    {
        $rules = [];

        foreach (PaymentMethodEnum::values() as $method) {
            $rules["payment.methods.{$method}"] = ['nullable', 'boolean'];
        }

        return $rules;
    }
}

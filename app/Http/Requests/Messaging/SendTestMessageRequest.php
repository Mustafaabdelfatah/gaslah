<?php

namespace App\Http\Requests\Messaging;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * Send the WhatsApp test message, to prove the tenant's messaging is wired up.
 */
class SendTestMessageRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'phone' => ['required', 'string', 'max:32'],
        ];
    }

    public function phone(): string
    {
        return $this->string('phone')->toString();
    }
}

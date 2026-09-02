<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\Tenancy\TenantFormRequest;
use Illuminate\Validation\Rule;

class SendOrderNotificationRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['whatsapp', 'sms'])],
        ];
    }

    public function channel(): string
    {
        return $this->string('channel')->lower()->toString();
    }
}

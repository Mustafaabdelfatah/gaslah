<?php

namespace App\Http\Requests\Orders;

/**
 * Verify a wallet-consent code and mint the one-shot proof a wallet payment must carry.
 */
class VerifyPosOtpRequest extends PosOtpRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'code' => ['required', 'string', 'max:12'],
        ];
    }

    public function code(): string
    {
        return $this->string('code')->toString();
    }
}

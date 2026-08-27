<?php

namespace App\Http\Requests\Orders;

use App\Http\Requests\BaseFormRequest;

/**
 * Request a wallet-consent code for a customer at the counter.
 *
 * The customer is only checked to exist here; that it belongs to the caller's
 * organization is a tenancy question the controller settles, so a foreign id reads as
 * "not found" rather than as a validation error that would confirm the row exists.
 */
class PosOtpRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'integer'],
        ];
    }

    public function customerId(): int
    {
        return $this->integer('customer_id');
    }
}

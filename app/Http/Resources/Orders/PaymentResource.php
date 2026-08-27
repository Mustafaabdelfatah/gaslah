<?php

namespace App\Http\Resources\Orders;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One payment taken against an order.
 */
class PaymentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'method' => $this->method,
            'amount' => $this->amount,
            'reference' => $this->reference,
            'verify_mode' => $this->verify_mode,
            'via_gateway' => (bool) $this->via_gateway,
            'shift_id' => $this->shift_id,
            'settlement_id' => $this->settlement_id,
            'created_at' => $this->created_at,
        ];
    }
}

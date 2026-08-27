<?php

namespace App\Http\Requests\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Advance an order to the next workflow status. Whether the move is legal from where the
 * order stands is the status service's decision, not a validation rule.
 */
class UpdateOrderStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(OrderStatusEnum::class)],
        ];
    }

    public function status(): OrderStatusEnum
    {
        return OrderStatusEnum::from($this->string('status')->toString());
    }
}

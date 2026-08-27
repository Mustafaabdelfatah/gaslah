<?php

namespace App\Http\Requests\Driver;

use App\Enum\Delivery\DeliveryStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rules\Enum;

/**
 * Move a delivery to its next state. Whether the move is legal from where the job stands
 * — and whether its completion gates are satisfied — is the driver service's decision.
 */
class AdvanceDeliveryRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(DeliveryStatusEnum::class)],
        ];
    }

    public function status(): DeliveryStatusEnum
    {
        return DeliveryStatusEnum::from($this->string('status')->toString());
    }
}

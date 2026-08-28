<?php

namespace App\Http\Requests\Market;

use App\Enum\Market\MarketOrderStatusEnum;
use App\Http\Requests\BaseFormRequest;
use Illuminate\Validation\Rule;

/**
 * A supplier moving one of their orders along.
 *
 * Whether the move is legal from where the order stands is the status machine's decision,
 * not a validation rule — this only refuses targets that are not states at all.
 */
class UpdateMarketOrderStatusRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(MarketOrderStatusEnum::targetValues())],
        ];
    }

    public function status(): MarketOrderStatusEnum
    {
        return MarketOrderStatusEnum::from($this->string('status')->toString());
    }
}

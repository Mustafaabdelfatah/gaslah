<?php

namespace App\Http\Requests\Driver;

use App\Http\Requests\BaseFormRequest;

/**
 * Proof-of-handover photo, taken at collection or at the door.
 */
class StoreDeliveryPhotoRequest extends BaseFormRequest
{
    public function rules(): array
    {
        return [
            'kind' => ['required', 'in:pickup,delivery'],
            'image' => ['required', 'string'],
        ];
    }

    public function kind(): string
    {
        return $this->string('kind')->toString();
    }

    public function image(): string
    {
        return $this->string('image')->toString();
    }
}

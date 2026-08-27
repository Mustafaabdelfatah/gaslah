<?php

namespace App\Http\Requests\Loyalty;

use App\Http\Requests\Tenancy\TenantFormRequest;

/**
 * A manual points correction, up or down. Zero is refused: an adjustment that changes
 * nothing is a mistake, not an instruction.
 */
class AdjustPointsRequest extends TenantFormRequest
{
    public function rules(): array
    {
        return [
            'points' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ];
    }

    public function points(): float
    {
        return (float) $this->input('points');
    }

    public function note(): ?string
    {
        return $this->input('note');
    }
}

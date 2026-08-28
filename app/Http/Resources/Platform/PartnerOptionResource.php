<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A partner as a pickable name and nothing else.
 *
 * Deliberately separate from {@see PlatformPartnerResource}: this one feeds the expense
 * form, which an accountant may open without being entitled to see stakes or what anyone
 * is owed. Keeping it a distinct class means a field added to the full resource cannot
 * leak here by accident.
 */
class PartnerOptionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
        ];
    }
}

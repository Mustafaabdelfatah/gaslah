<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One cash payout to a partner.
 */
class PartnerDistributionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'partner_id' => $this->partner_id,
            'partner_name' => $this->whenLoaded('partner', fn () => $this->partner?->name),
            'amount' => $this->amount,
            'date' => $this->date,
            'note' => $this->note,
            'recorded_by_id' => $this->recorded_by_id,
            'created_at' => $this->created_at,
        ];
    }
}

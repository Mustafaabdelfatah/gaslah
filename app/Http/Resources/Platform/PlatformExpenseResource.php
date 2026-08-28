<?php

namespace App\Http\Resources\Platform;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An operating cost of the platform, and — when a partner fronted it — whether the
 * platform has settled up.
 */
class PlatformExpenseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'category' => $this->category,
            'amount' => $this->amount,
            'note' => $this->note,

            'paid_by_partner_id' => $this->paid_by_partner_id,
            'paid_by_partner' => $this->whenLoaded('partner', fn () => $this->partner?->name),
            'is_partner_funded' => $this->paid_by_partner_id !== null,
            'reimbursed_at' => $this->reimbursed_at,
            'is_outstanding' => $this->paid_by_partner_id !== null && $this->reimbursed_at === null,

            'created_by_id' => $this->created_by_id,
            'created_at' => $this->created_at,
        ];
    }
}

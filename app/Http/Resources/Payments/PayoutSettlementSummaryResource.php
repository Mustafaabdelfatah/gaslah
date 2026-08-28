<?php

namespace App\Http\Resources\Payments;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout batch as the tenant sees its own history.
 *
 * Narrower than the operator's view: no bank snapshot, no approval trail — the tenant
 * needs to know what was sent, when, and for how much.
 */
class PayoutSettlementSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'urgent' => (bool) $this->urgent,

            'gross_amount' => $this->gross_amount,
            'fee_amount' => $this->fee_amount,
            'net_amount' => $this->net_amount,

            'transfer_ref' => $this->transfer_ref,
            'sent_at' => $this->sent_at,
            'created_at' => $this->created_at,
        ];
    }
}

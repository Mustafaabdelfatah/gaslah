<?php

namespace App\Http\Resources\Reports;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A till shift.
 *
 * The expected cash and the variance only exist once the shift is closed and counted, so
 * they stay null while it is still open rather than reporting a misleading zero.
 */
class ShiftResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $isClosed = $this->closed_at !== null;

        return [
            'id' => $this->id,
            'status' => $isClosed ? 'closed' : 'open',

            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'user_id' => $this->user_id,
            'cashier_name' => $this->whenLoaded('user', fn () => $this->user?->name),

            'opening_float' => round((float) $this->opening_float, 2),
            'expected_cash' => $isClosed ? round((float) $this->expected_cash, 2) : null,
            'actual_cash' => $this->actual_cash === null ? null : round((float) $this->actual_cash, 2),
            'variance' => $this->variance === null ? null : round((float) $this->variance, 2),

            'opened_at' => $this->opened_at,
            'closed_at' => $this->closed_at,
        ];
    }
}

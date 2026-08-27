<?php

namespace App\Http\Resources\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\User;
use App\Services\Tenancy\PlatformAccessService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payout batch.
 *
 * The bank snapshot is need-to-know: only the admin who actually executes transfers
 * (manage_payouts) sees the full IBAN — a finance viewer gets the last four digits, enough
 * to reconcile a statement without handing the whole account number to every reader.
 */
class PayoutSettlementResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'urgent' => (bool) $this->urgent,

            'organization_id' => $this->organization_id,
            'organization' => $this->whenLoaded('organization', fn () => [
                'id' => $this->organization?->id,
                'name' => $this->organization?->name,
            ]),

            'period_start' => $this->period_start,
            'period_end' => $this->period_end,

            'payment_count' => $this->payment_count,
            'gross_amount' => $this->gross_amount,
            'fee_amount' => $this->fee_amount,
            'net_amount' => $this->net_amount,
            'currency' => $this->currency,

            'bank_snapshot' => $this->bankSnapshot($request),

            'approve_count' => $this->when($this->approve_count !== null, fn () => (int) $this->approve_count),
            'approvals' => $this->whenLoaded('approvals'),
            'payments' => $this->whenLoaded('payments'),

            'requested_by_id' => $this->requested_by_id,
            'created_by_id' => $this->created_by_id,
            'approved_at' => $this->approved_at,
            'sent_by_id' => $this->sent_by_id,
            'sent_at' => $this->sent_at,
            'transfer_ref' => $this->transfer_ref,

            'note' => $this->note,
            'rejected_reason' => $this->rejected_reason,

            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function bankSnapshot(Request $request): ?array
    {
        $bank = $this->bank_snapshot;

        if (! is_array($bank)) {
            return null;
        }

        $user = $request->user();

        if (app(PlatformAccessService::class)->has(
            $user instanceof User ? $user : null,
            PlatformPermissionEnum::ManagePayouts,
        )) {
            return $bank;
        }

        $iban = (string) ($bank['iban'] ?? '');
        $bank['iban'] = $iban === '' ? '' : '****'.substr($iban, -4);

        return $bank;
    }
}

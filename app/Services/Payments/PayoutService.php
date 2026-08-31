<?php

namespace App\Services\Payments;

use App\Enum\Payments\SettlementDecisionEnum;
use App\Enum\Payments\SettlementStatusEnum;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\PayoutSetting;
use App\Models\PayoutSettlement;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settles the platform-held gateway pool to organizations under maker-checker control.
 *
 * The pool is every via_gateway payment not yet reserved by a settlement. Creating a
 * batch reserves the whole pool atomically (unique open-settlement index + conditional
 * reservation), N distinct admins must approve, the creator may not vote, one vote per
 * admin, and a rejection or cancel releases the payments back to the pool.
 */
class PayoutService
{
    private const DEFAULTS = [
        'fee_fixed' => 0,
        'fee_percent' => 0,
        'min_amount' => 0,
        'required_approvals' => 2,
        'days' => ['mon'],
    ];

    /**
     * The platform payout settings (saved, or a defaults template).
     */
    public function settings(): PayoutSetting
    {
        return PayoutSetting::query()->first() ?? new PayoutSetting(self::DEFAULTS);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveSettings(array $data): PayoutSetting
    {
        $existing = PayoutSetting::query()->first();

        if ($existing !== null) {
            $existing->update($data);

            return $existing->refresh();
        }

        return PayoutSetting::query()->create([...self::DEFAULTS, ...$data]);
    }

    /**
     * The platform fee for a gross amount, clamped to [0, gross].
     */
    public function defaultFee(float $gross): float
    {
        $settings = $this->settings();
        $fee = (float) $settings->fee_fixed + $gross * (float) $settings->fee_percent / 100;

        return round(min(max(0, $fee), $gross), 2);
    }

    /**
     * The unsettled pool summary for one organization.
     *
     * @return array{gross: float, count: int, from: string|null, to: string|null}
     */
    public function unsettledSummary(int $organizationId): array
    {
        $row = $this->poolQuery()
            ->where('orders.organization_id', $organizationId)
            ->selectRaw('COALESCE(SUM(payments.amount),0) as gross, COUNT(*) as cnt, MIN(payments.created_at) as from_at, MAX(payments.created_at) as to_at')
            ->first();

        return [
            'gross' => round((float) $row->gross, 2),
            'count' => (int) $row->cnt,
            'from' => $row->from_at,
            'to' => $row->to_at,
        ];
    }

    /**
     * Unsettled balances across all organizations, richest first.
     *
     * @return Collection<int, object>
     */
    public function balances(): Collection
    {
        return $this->poolQuery()
            ->selectRaw('orders.organization_id as organization_id, SUM(payments.amount) as gross, COUNT(*) as cnt, MIN(payments.created_at) as from_at, MAX(payments.created_at) as to_at')
            ->groupBy('orders.organization_id')
            ->orderByDesc('gross')
            ->get();
    }

    /**
     * Create a settlement batch claiming an organization's whole pool.
     */
    public function createBatch(Organization $organization, ?int $createdById, ?float $fee, bool $urgent = false, ?int $requestedById = null, ?string $note = null): PayoutSettlement
    {
        try {
            return DB::transaction(function () use ($organization, $createdById, $fee, $urgent, $requestedById, $note) {
                abort_if($this->hasOpenSettlement($organization->getKey()), Response::HTTP_CONFLICT, __('api.payout_open_exists'));

                $payments = $this->poolQuery()
                    ->where('orders.organization_id', $organization->getKey())
                    ->get(['payments.id', 'payments.amount', 'payments.created_at']);

                abort_if($payments->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_pool_empty'));

                $gross = round((float) $payments->sum('amount'), 2);
                $feeAmount = $fee === null ? $this->defaultFee($gross) : round(min(max(0, $fee), $gross), 2);

                $settlement = PayoutSettlement::query()->create([
                    'organization_id' => $organization->getKey(),
                    'status' => SettlementStatusEnum::PendingApproval->value,
                    'urgent' => $urgent,
                    'period_start' => $payments->min('created_at'),
                    'period_end' => $payments->max('created_at'),
                    'payment_count' => $payments->count(),
                    'gross_amount' => $gross,
                    'fee_amount' => $feeAmount,
                    'net_amount' => round($gross - $feeAmount, 2),
                    'bank_snapshot' => $organization->payout_config['bank'] ?? null,
                    'requested_by_id' => $requestedById,
                    'created_by_id' => $createdById,
                    'note' => $note,
                ]);

                $reserved = Payment::query()
                    ->whereIn('id', $payments->pluck('id'))
                    ->whereNull('settlement_id')
                    ->update(['settlement_id' => $settlement->getKey()]);

                abort_if($reserved !== $payments->count(), Response::HTTP_CONFLICT, __('api.payout_race'));

                return $settlement;
            });
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                abort(Response::HTTP_CONFLICT, __('api.payout_open_exists'));
            }

            throw $exception;
        }
    }

    /**
     * Cast an approval vote; the Nth distinct approval approves the settlement.
     */
    public function approve(PayoutSettlement $settlement, User $admin, ?string $note): PayoutSettlement
    {
        abort_unless($settlement->status === SettlementStatusEnum::PendingApproval, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_not_pending'));
        abort_if($settlement->created_by_id === $admin->getKey(), Response::HTTP_FORBIDDEN, __('api.payout_creator_cannot_approve'));

        return DB::transaction(function () use ($settlement, $admin, $note) {
            $this->recordVote($settlement, $admin, SettlementDecisionEnum::Approve, $note);

            $approvals = $settlement->approvals()->where('decision', SettlementDecisionEnum::Approve->value)->count();

            if ($approvals >= (int) $this->settings()->required_approvals) {
                $settlement->forceFill([
                    'status' => SettlementStatusEnum::Approved->value,
                    'approved_at' => Carbon::now(),
                ])->save();
            }

            return $settlement->refresh();
        });
    }

    /**
     * A single rejection kills the settlement and releases its payments.
     */
    public function reject(PayoutSettlement $settlement, User $admin, ?string $reason): PayoutSettlement
    {
        abort_unless($settlement->status === SettlementStatusEnum::PendingApproval, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_not_pending'));

        return DB::transaction(function () use ($settlement, $admin, $reason) {
            $this->recordVote($settlement, $admin, SettlementDecisionEnum::Reject, $reason);

            $settlement->forceFill([
                'status' => SettlementStatusEnum::Rejected->value,
                'rejected_reason' => $reason,
            ])->save();

            $this->releasePayments($settlement);

            return $settlement->refresh();
        });
    }

    /**
     * Record the bank transfer against an approved settlement.
     */
    public function markSent(PayoutSettlement $settlement, User $admin, string $transferRef): PayoutSettlement
    {
        abort_unless($settlement->status === SettlementStatusEnum::Approved, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_not_approved'));

        $settlement->forceFill([
            'status' => SettlementStatusEnum::Sent->value,
            'sent_by_id' => $admin->getKey(),
            'sent_at' => Carbon::now(),
            'transfer_ref' => $transferRef,
        ])->save();

        return $settlement->refresh();
    }

    /**
     * Cancel an open settlement and release its payments.
     */
    public function cancel(PayoutSettlement $settlement, ?string $reason): PayoutSettlement
    {
        abort_unless($settlement->status->isOpen(), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_not_open'));

        return DB::transaction(function () use ($settlement, $reason) {
            $settlement->forceFill([
                'status' => SettlementStatusEnum::Cancelled->value,
                'rejected_reason' => $reason,
            ])->save();

            $this->releasePayments($settlement);

            return $settlement->refresh();
        });
    }

    /**
     * Change the fee on a pending settlement; this clears all approval votes.
     */
    public function updateFee(PayoutSettlement $settlement, float $fee): PayoutSettlement
    {
        abort_unless($settlement->status === SettlementStatusEnum::PendingApproval, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payout_not_pending'));

        $gross = (float) $settlement->gross_amount;
        $feeAmount = round(min(max(0, $fee), $gross), 2);

        return DB::transaction(function () use ($settlement, $gross, $feeAmount) {
            // The amounts the voters saw changed, so their approvals no longer hold.
            $settlement->approvals()->delete();

            $settlement->forceFill([
                'fee_amount' => $feeAmount,
                'net_amount' => round($gross - $feeAmount, 2),
            ])->save();

            return $settlement->refresh();
        });
    }

    /**
     * Release a settlement's payments back to the pool.
     */
    public function releasePayments(PayoutSettlement $settlement): void
    {
        Payment::query()->where('settlement_id', $settlement->getKey())->update(['settlement_id' => null]);
    }

    /**
     * Run the scheduled draw for a weekday, creating a batch per eligible organization.
     *
     * @return array{created: array<int, int>, skipped: int}
     */
    public function runDue(string $weekday): array
    {
        $settings = $this->settings();
        $minAmount = (float) $settings->min_amount;
        $created = [];
        $skipped = 0;

        foreach ($this->balances() as $balance) {
            if ((float) $balance->gross < $minAmount) {
                $skipped++;

                continue;
            }

            $organization = Organization::query()->find($balance->organization_id);
            if ($organization === null || ! $this->scheduledToday($organization, $settings, $weekday)) {
                $skipped++;

                continue;
            }

            try {
                $this->createBatch($organization, null, null);
                $created[] = (int) $balance->organization_id;
            } catch (\Throwable) {
                // A racing/empty pool is skipped, never stops the run.
                $skipped++;
            }
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function recordVote(PayoutSettlement $settlement, User $admin, SettlementDecisionEnum $decision, ?string $note): void
    {
        try {
            $settlement->approvals()->create([
                'admin_id' => $admin->getKey(),
                'decision' => $decision->value,
                'note' => $note,
            ]);
        } catch (QueryException $exception) {
            if ($this->isDuplicateKey($exception)) {
                abort(Response::HTTP_CONFLICT, __('api.payout_already_voted'));
            }

            throw $exception;
        }
    }

    private function scheduledToday(Organization $organization, PayoutSetting $settings, string $weekday): bool
    {
        $days = $organization->payout_config['days'] ?? $settings->days ?? self::DEFAULTS['days'];

        return in_array(strtolower($weekday), array_map('strtolower', (array) $days), true);
    }

    /**
     * Whether a batch is already awaiting approval or transfer.
     *
     * Public because the tenant's own screen has to say why the urgent button is
     * refusing before it is pressed — the guard inside createBatch answers a 409,
     * which is the wrong moment to learn it.
     */
    public function hasOpenSettlement(int $organizationId): bool
    {
        return PayoutSettlement::query()->where('organization_id', $organizationId)->open()->exists();
    }

    private function poolQuery(): Builder
    {
        return DB::table('payments')
            ->join('orders', 'orders.id', '=', 'payments.order_id')
            ->where('payments.via_gateway', true)
            ->whereNull('payments.settlement_id');
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}

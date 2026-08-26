<?php

namespace App\Services\Payments;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\OnlineChargePurposeEnum;
use App\Enum\Payments\OnlineChargeStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Models\OnlineCharge;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Orders\OrderAccountingService;
use App\Services\Payments\Gateways\PaymentGatewayManager;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Executes a gateway payment against an order and settles it exactly once.
 *
 * The capture (a read-only gateway call) runs before the transaction so a slow gateway
 * never holds a row lock. The legal idempotency key is `gateway:{txnId}` alone — the
 * client-controlled channel and order id are deliberately excluded — and the unique
 * reference index makes a second arrival (browser redirect, webhook, or provider retry)
 * a no-op. Every gateway payment writes a Payment (via_gateway=true, so it joins the
 * settlement pool) and a parallel OnlineCharge, without which it would be invisible to
 * the platform monitor.
 */
class OnlinePaymentService
{
    public function __construct(
        private readonly PaymentGatewayManager $gateways,
        private readonly OrderAccountingService $accounting,
    ) {}

    /**
     * A summary for the public payment page. The amount due is recomputed live (0 for a
     * cancelled order so its card form cannot load).
     *
     * @return array<string, mixed>
     */
    public function linkSummary(Order $order): array
    {
        $order->loadMissing('organization:id,name,default_currency', 'branch:id,name');
        $cancelled = $order->status === OrderStatusEnum::Cancelled;
        $due = $cancelled ? 0.0 : $order->remaining();

        return [
            'order_no' => $order->order_no,
            'seller' => $order->organization?->name ?? $order->branch?->name,
            'amount_due' => $due,
            'currency' => $order->organization?->default_currency ?? 'SAR',
            'paid' => $order->payment_status === PaymentStatusEnum::Paid,
            'publishable_key' => $this->gateways->resolve()->publishableKey(),
            'order_id' => $order->getKey(),
        ];
    }

    /**
     * Capture and settle a gateway payment for the order.
     *
     * @return array<string, mixed>
     */
    public function pay(Order $order, string $channel, ?float $amount, ?string $paymentRef): array
    {
        abort_if($order->status === OrderStatusEnum::Cancelled, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_cancelled'));

        $remaining = $order->remaining();
        if ($remaining <= 0) {
            return $this->settledResponse($order, false);
        }

        $applied = $amount === null ? $remaining : round(min($amount, $remaining), 2);

        // Capture BEFORE the transaction (read-only gateway call).
        $gateway = $this->gateways->resolve();
        $txnId = $gateway->capture($order, $applied, $channel, $paymentRef);

        abort_if($txnId === '', Response::HTTP_BAD_GATEWAY, __('api.payment_gateway_unreachable'));

        $posted = $this->settle($order, $txnId, $applied, $gateway->provider());

        // Best-effort ledger sync after commit; a failure is reported, never breaks the
        // payment, and the entry is idempotent.
        if ($posted) {
            $this->postAccounting($order);
        }

        return $this->settledResponse($order->refresh(), $posted);
    }

    /**
     * Settle a paid charge arriving via the webhook. A cancelled order is not settled
     * (the gateway holds the money — refund from the provider dashboard, not here).
     */
    public function settleFromWebhook(Order $order, string $txnId, float $amount, string $provider): void
    {
        if ($order->status === OrderStatusEnum::Cancelled) {
            // The gateway holds the money — a manual refund from the provider dashboard
            // is required, not an automatic settlement.
            Log::warning(__('api.webhook_cancelled_order', ['order_no' => $order->order_no]));

            return;
        }

        if ($this->settle($order, $txnId, $amount, $provider)) {
            $this->postAccounting($order);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * The single write path shared by the pay page and the webhook. Returns true only
     * when a new payment was recorded (so accounting should be re-synced).
     */
    private function settle(Order $order, string $txnId, float $requested, string $provider): bool
    {
        $reference = 'gateway:'.$txnId;

        try {
            return DB::transaction(function () use ($order, $reference, $requested, $provider, $txnId) {
                $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->first();

                if ($locked === null || $locked->status === OrderStatusEnum::Cancelled) {
                    return false;
                }

                $existing = Payment::query()->where('reference', $reference)->first();
                if ($existing !== null) {
                    // Same reference on a different order is a replay against another cart.
                    abort_if($existing->order_id !== $locked->getKey(), Response::HTTP_CONFLICT, __('api.payment_used_for_other_order'));

                    return false;
                }

                $remaining = $locked->remaining();
                if ($remaining <= 0) {
                    return false;
                }

                $applied = round(min($requested, $remaining), 2);
                if ($applied <= 0) {
                    return false;
                }

                $payment = $locked->payments()->create([
                    'method' => PaymentMethodEnum::Card->value,
                    'amount' => $applied,
                    'reference' => $reference,
                    'via_gateway' => true,
                ]);

                OnlineCharge::query()->create([
                    'organization_id' => $locked->organization_id,
                    'provider' => $provider,
                    'provider_ref' => $txnId,
                    'purpose' => OnlineChargePurposeEnum::OrderPayment->value,
                    'order_id' => $locked->getKey(),
                    'customer_id' => $locked->customer_id,
                    'amount' => $applied,
                    'status' => OnlineChargeStatusEnum::Paid->value,
                    'idempotency_key' => $reference,
                    'raw_status' => 'paid',
                ]);

                $paidTotal = round(min((float) $locked->paid_total + $applied, (float) $locked->grand_total), 2);
                $locked->forceFill([
                    'paid_total' => $paidTotal,
                    'payment_status' => $paidTotal >= (float) $locked->grand_total
                        ? PaymentStatusEnum::Paid->value
                        : PaymentStatusEnum::Partial->value,
                ])->save();

                unset($payment);

                return true;
            });
        } catch (QueryException $exception) {
            // A concurrent settlement won the unique-reference race: idempotent no-op.
            if ($this->isDuplicateKey($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    private function postAccounting(Order $order): void
    {
        try {
            $this->accounting->sync($order->refresh());
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function settledResponse(Order $order, bool $posted): array
    {
        return [
            'status' => $order->payment_status->value,
            'paid' => $order->payment_status === PaymentStatusEnum::Paid,
            'paid_total' => round((float) $order->paid_total, 2),
            'amount_due' => $order->remaining(),
            'posted' => $posted,
        ];
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}

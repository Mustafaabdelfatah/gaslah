<?php

namespace App\Services\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Models\Order;
use App\Services\Payments\WalletService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Collecting money on an order that already exists — the debt path, not the sale path.
 *
 * The till writes payments while it writes the order; this is for afterwards: the partial
 * that gets settled a week later, the آجل the customer comes back to clear. Only counter
 * methods are taken here — a wallet draw needs the customer's OTP and belongs to the POS
 * flow, and a card via the gateway arrives through the webhook.
 */
class OrderCollectionService
{
    public function __construct(
        private readonly OrderAccountingService $accounting,
        private readonly PosOtpService $otp,
        private readonly WalletService $wallet,
    ) {}

    public function collect(
        Order $order,
        PaymentMethodEnum $method,
        float $amount,
        ?string $reference = null,
        ?string $otpToken = null,
    ): Order {
        return DB::transaction(function () use ($order, $method, $amount, $reference, $otpToken) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            // A cancelled sale has been reversed in the books; money against it would sit
            // on a receivable that no longer exists.
            abort_if(
                $locked->status === OrderStatusEnum::Cancelled,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                __('api.order_already_cancelled'),
            );

            $remaining = $locked->remaining();
            abort_if($remaining <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_already_paid'));

            if ($method === PaymentMethodEnum::Deferred) {
                $locked->forceFill([
                    'payment_status' => PaymentStatusEnum::Deferred->value,
                    'archived_at' => null,
                ])->save();

                return $locked;
            }

            // Refused rather than clamped: a figure larger than the debt is a typo, and
            // quietly taking less than the cashier typed hides it from them.
            abort_if(
                round($amount, 2) > $remaining,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                __('api.payment_exceeds_remaining', ['remaining' => number_format($remaining, 2)]),
            );

            if ($method === PaymentMethodEnum::Wallet) {
                $customer = $locked->customer()->first();
                abort_if($customer === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.wallet_customer_required'));

                if (! $this->otp->reserve((string) $otpToken, $customer)) {
                    abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_consent_required'));
                }

                $this->wallet->debit(
                    $customer,
                    $amount,
                    __('api.order_sale_memo', ['order_no' => $locked->order_no]),
                    $locked->getKey(),
                );
            }

            $payment = $locked->payments()->create([
                'method' => $method->value,
                'amount' => round($amount, 2),
                'reference' => $reference,
            ]);

            $paidTotal = round((float) $locked->paid_total + $payment->amount, 2);
            $locked->forceFill([
                'paid_total' => $paidTotal,
                'payment_status' => $paidTotal >= (float) $locked->grand_total
                    ? PaymentStatusEnum::Paid->value
                    : PaymentStatusEnum::Partial->value,
            ])->save();

            $this->accounting->postPayment($locked, $payment);

            return $locked;
        });
    }
}

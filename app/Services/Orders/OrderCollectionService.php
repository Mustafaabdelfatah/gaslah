<?php

namespace App\Services\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Models\Order;
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
    public function __construct(private readonly OrderAccountingService $accounting) {}

    public function collect(Order $order, PaymentMethodEnum $method, float $amount, ?string $reference = null): Order
    {
        return DB::transaction(function () use ($order, $method, $amount, $reference) {
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

            // Refused rather than clamped: a figure larger than the debt is a typo, and
            // quietly taking less than the cashier typed hides it from them.
            abort_if(
                round($amount, 2) > $remaining,
                Response::HTTP_UNPROCESSABLE_ENTITY,
                __('api.payment_exceeds_remaining', ['remaining' => number_format($remaining, 2)]),
            );

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

<?php

namespace App\Services\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\PaymentVerifyModeEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Service;
use App\Services\Payments\WalletService;
use App\Services\Subscriptions\SubscriptionConsumptionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Creates point-of-sale orders.
 *
 * Every price is re-derived from the catalog — a client price is never trusted. The
 * cart carries an idempotency key so a lost response cannot be double-billed, and a
 * stored-value payment burns the customer's consent proof atomically before any
 * wallet balance or subscription quota moves.
 */
class PosService
{
    private const MAX_CREATE_ATTEMPTS = 6;

    /**
     * A settlement drawn from the customer's subscription rather than a payment
     * method. It writes no payment row (subscription is absent from PaymentMethodEnum).
     */
    private const SUBSCRIPTION_METHOD = 'subscription';

    public function __construct(
        private readonly OrderPricingService $pricing,
        private readonly OrderNumberService $numbers,
        private readonly WalletService $wallet,
        private readonly PosOtpService $otp,
        private readonly OrderAccountingService $accounting,
        private readonly SubscriptionConsumptionService $subscriptions,
    ) {}

    /**
     * Create an order at the caller's branch and settle its optional payment.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(int $organizationId, Branch $branch, ?int $cashierId, array $data): Order
    {
        // First idempotency barrier: an already-recorded cart is returned untouched.
        $clientRequestId = $data['client_request_id'] ?? null;
        if ($clientRequestId !== null && ($existing = $this->findByClientRequest($branch, $clientRequestId)) !== null) {
            return $existing;
        }

        $customer = $this->resolveCustomer($organizationId, $data['customer_id']);
        $lines = $this->repriceLines($organizationId, $data['items'] ?? []);

        $taxRate = (float) ($this->organizationTaxRate($organizationId) ?? 15);
        $totals = $this->pricing->computeTotals(
            $lines,
            round((float) ($data['express_surcharge'] ?? 0), 2),
            $data['discount'] ?? null,
            $taxRate,
        );

        $payment = $data['payment'] ?? null;
        $this->validatePayment($payment, $customer, $totals['grand_total']);

        return $this->createWithRetry($organizationId, $branch, $cashierId, $customer, $data, $lines, $totals, $taxRate, $payment, $clientRequestId);
    }

    /*
    |--------------------------------------------------------------------------
    | Order creation
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<int, array{service: Service, garment_type_id: int|null, is_express: bool, quantity: float, unit_price: float, notes: string|null}>  $lines
     * @param  array{subtotal: float, discount_total: float, tax_total: float, grand_total: float}  $totals
     */
    private function createWithRetry(int $organizationId, Branch $branch, ?int $cashierId, Customer $customer, array $data, array $lines, array $totals, float $taxRate, ?array $payment, ?string $clientRequestId): Order
    {
        $attempt = 0;

        while (true) {
            $identifiers = $this->numbers->generate($branch, $attempt);

            try {
                return DB::transaction(function () use ($organizationId, $branch, $cashierId, $customer, $data, $lines, $totals, $taxRate, $payment, $clientRequestId, $identifiers) {
                    $order = Order::query()->create([
                        'organization_id' => $organizationId,
                        'branch_id' => $branch->getKey(),
                        'customer_id' => $customer->getKey(),
                        'cashier_id' => $cashierId,
                        'order_no' => $identifiers['order_no'],
                        'barcode' => $identifiers['barcode'],
                        'status' => OrderStatusEnum::Received->value,
                        'priority' => ($data['priority'] ?? 'normal'),
                        'payment_status' => PaymentStatusEnum::Unpaid->value,
                        'due_at' => $data['due_at'] ?? null,
                        'notes' => $data['notes'] ?? null,
                        'subtotal' => $totals['subtotal'],
                        'discount_total' => $totals['discount_total'],
                        'tax_total' => $totals['tax_total'],
                        'tax_rate' => $taxRate,
                        'grand_total' => $totals['grand_total'],
                        'paid_total' => 0,
                        'client_request_id' => $clientRequestId,
                    ]);

                    foreach ($lines as $line) {
                        $order->items()->create([
                            'service_id' => $line['service']->getKey(),
                            'garment_type_id' => $line['garment_type_id'],
                            'is_express' => $line['is_express'],
                            'quantity' => $line['quantity'],
                            'unit_price' => $line['unit_price'],
                            'line_total' => round($line['unit_price'] * $line['quantity'], 2),
                            'notes' => $line['notes'],
                        ]);
                    }

                    $this->settlePayment($order, $customer, $payment);

                    return $order->refresh()->load('items', 'payments');
                });
            } catch (QueryException $exception) {
                if (! $this->isDuplicateKey($exception)) {
                    throw $exception;
                }

                // A concurrent re-sync of the same cart won the idempotency race.
                if ($clientRequestId !== null && ($existing = $this->findByClientRequest($branch, $clientRequestId)) !== null) {
                    return $existing;
                }

                // Otherwise the order number/barcode collided — retry with a higher
                // sequence.
                if (++$attempt >= self::MAX_CREATE_ATTEMPTS) {
                    abort(Response::HTTP_CONFLICT, __('api.order_number_generation_failed'));
                }
            }
        }
    }

    /**
     * After the order commits, bring its ledger in step. Best-effort: a posting
     * failure is reported but never breaks the sale, and the entry is idempotent so a
     * backfill re-posts it.
     */
    public function postAccounting(Order $order): void
    {
        try {
            $this->accounting->sync($order);
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Payment settlement
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>|null  $payment
     */
    private function settlePayment(Order $order, Customer $customer, ?array $payment): void
    {
        $remaining = $order->remaining();

        if ($payment === null || $remaining <= 0) {
            $order->forceFill(['payment_status' => $remaining <= 0 ? PaymentStatusEnum::Paid->value : PaymentStatusEnum::Unpaid->value])->save();

            return;
        }

        // A subscription draw is stored value too: burn the customer's one-shot
        // consent before quota/balance moves. This runs inside the order transaction,
        // so a later quota/payment refusal rolls the proof consumption back with it.
        if (($payment['method'] ?? null) === self::SUBSCRIPTION_METHOD) {
            if (! $this->otp->reserve((string) ($payment['otp_token'] ?? ''), $customer)) {
                abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_consent_required'));
            }

            $this->subscriptions->consume($order, $customer);

            return;
        }

        $method = PaymentMethodEnum::from($payment['method']);

        match ($method) {
            PaymentMethodEnum::Deferred => $this->settleDeferred($order, $remaining),
            PaymentMethodEnum::Cash => $this->settleCash($order, $customer, $payment, $remaining),
            PaymentMethodEnum::Card, PaymentMethodEnum::Transfer => $this->settleVerified($order, $method, $payment, $remaining),
            PaymentMethodEnum::Wallet => $this->settleWallet($order, $customer, $payment, $remaining),
        };
    }

    private function settleDeferred(Order $order, float $remaining): void
    {
        // A status marker, no payment row and no ledger movement.
        $order->forceFill(['payment_status' => PaymentStatusEnum::Deferred->value])->save();
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function settleCash(Order $order, Customer $customer, array $payment, float $remaining): void
    {
        $collect = $remaining;
        $cashTendered = null;

        if (isset($payment['amount'])) {
            $tendered = round((float) $payment['amount'], 2);
            abort_if($tendered <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.amount_received_invalid'));

            if ($tendered < $remaining) {
                $collect = $tendered;
            } elseif ($tendered > $remaining && ($payment['overpay_to'] ?? 'change') === 'wallet') {
                // Overpay kept in the drawer becomes wallet value so cash reconciles.
                $this->wallet->credit(
                    $customer,
                    round($tendered - $remaining, 2),
                    WalletTransactionTypeEnum::Topup,
                    __('api.wallet_topped_up_change'),
                    $order->getKey(),
                );
            } elseif ($tendered > $remaining) {
                $cashTendered = $tendered;
            }
        }

        $this->recordPayment($order, PaymentMethodEnum::Cash, $collect, cashTendered: $cashTendered);
        $this->applyPaid($order, $collect, $remaining);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function settleVerified(Order $order, PaymentMethodEnum $method, array $payment, float $remaining): void
    {
        $this->recordPayment(
            $order,
            $method,
            $remaining,
            verifyMode: PaymentVerifyModeEnum::from($payment['verify_mode']),
            reference: isset($payment['reference']) ? trim((string) $payment['reference']) : null,
        );
        $this->applyPaid($order, $remaining, $remaining);
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function settleWallet(Order $order, Customer $customer, array $payment, float $remaining): void
    {
        // Burn the consent proof atomically BEFORE any money moves. If the burn does
        // not win, the debit never happens.
        if (! $this->otp->reserve((string) ($payment['otp_token'] ?? ''), $customer)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_consent_required'));
        }

        $lockedBalance = (float) DB::table('customers')->where('id', $customer->getKey())->lockForUpdate()->value('wallet_balance');
        $walletPart = round(min($lockedBalance, $remaining), 2);

        abort_if($walletPart <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.wallet_insufficient_balance'));

        $this->wallet->debit($customer, $walletPart, __('api.order_sale_memo', ['order_no' => $order->order_no]), $order->getKey());
        $this->recordPayment($order, PaymentMethodEnum::Wallet, $walletPart);

        $paid = $walletPart;
        $shortfall = round($remaining - $walletPart, 2);

        // The wallet shortfall may be topped up with a secondary card/cash/transfer.
        if ($shortfall > 0 && isset($payment['secondary'])) {
            $secondary = $payment['secondary'];
            $secondaryMethod = PaymentMethodEnum::from($secondary['method']);

            $this->recordPayment(
                $order,
                $secondaryMethod,
                $shortfall,
                verifyMode: isset($secondary['verify_mode']) ? PaymentVerifyModeEnum::from($secondary['verify_mode']) : null,
                reference: isset($secondary['reference']) ? trim((string) $secondary['reference']) : null,
            );
            $paid = $remaining;
        }

        $this->applyPaid($order, $paid, $remaining);
    }

    private function recordPayment(
        Order $order,
        PaymentMethodEnum $method,
        float $amount,
        ?PaymentVerifyModeEnum $verifyMode = null,
        ?string $reference = null,
        ?float $cashTendered = null,
    ): void {
        $order->payments()->create([
            'method' => $method->value,
            'amount' => $amount,
            'cash_tendered' => $cashTendered,
            'verify_mode' => $verifyMode?->value,
            'reference' => $reference,
        ]);
    }

    private function applyPaid(Order $order, float $collected, float $remaining): void
    {
        $paidTotal = round((float) $order->paid_total + $collected, 2);
        $order->forceFill([
            'paid_total' => $paidTotal,
            'payment_status' => $collected >= $remaining ? PaymentStatusEnum::Paid->value : PaymentStatusEnum::Partial->value,
        ])->save();
    }

    /*
    |--------------------------------------------------------------------------
    | Validation & resolution
    |--------------------------------------------------------------------------
    */

    /**
     * @param  array<string, mixed>|null  $payment
     */
    private function validatePayment(?array $payment, Customer $customer, float $grandTotal): void
    {
        if ($payment === null) {
            return;
        }

        // A subscription settlement has no terminal/manual verification mode. Its
        // one-shot OTP proof is burned atomically inside settlePayment instead.
        if (($payment['method'] ?? null) === self::SUBSCRIPTION_METHOD) {
            return;
        }

        $method = PaymentMethodEnum::tryFrom($payment['method'] ?? '');
        abort_if($method === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payment_not_confirmed'));

        // Card/transfer are collected outside the app and must be confirmed; a
        // terminal confirmation must carry the network reference.
        if ($method->requiresVerification()) {
            $this->assertVerified($payment);
        }

        if (isset($payment['secondary'])) {
            $secondaryMethod = PaymentMethodEnum::tryFrom($payment['secondary']['method'] ?? '');
            if ($secondaryMethod?->requiresVerification()) {
                $this->assertVerified($payment['secondary']);
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private function assertVerified(array $payment): void
    {
        $mode = PaymentVerifyModeEnum::tryFrom($payment['verify_mode'] ?? '');
        abort_if($mode === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payment_not_confirmed'));

        if ($mode === PaymentVerifyModeEnum::Terminal && empty($payment['reference'])) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.payment_terminal_reference_required'));
        }
    }

    private function resolveCustomer(int $organizationId, int $customerId): Customer
    {
        $customer = Customer::query()->forOrganization($organizationId)->find($customerId);

        abort_if($customer === null, Response::HTTP_NOT_FOUND, __('api.record_not_found'));

        return $customer;
    }

    /**
     * Re-price every line from the catalog. A missing or foreign service fails the
     * whole cart.
     *
     * @param  array<int, array<string, mixed>>  $items
     * @return array<int, array{service: Service, garment_type_id: int|null, is_express: bool, quantity: float, unit_price: float, notes: string|null}>
     */
    private function repriceLines(int $organizationId, array $items): array
    {
        abort_if($items === [], Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_items_required'));

        $serviceIds = collect($items)->pluck('service_id')->unique()->all();
        $services = Service::query()
            ->forOrganization($organizationId)
            ->whereIn('id', $serviceIds)
            ->get()
            ->keyBy('id');

        abort_if($services->count() !== count($serviceIds), Response::HTTP_NOT_FOUND, __('api.order_service_not_found'));

        return collect($items)->map(function (array $item) use ($services) {
            $service = $services[$item['service_id']];
            $express = (bool) ($item['is_express'] ?? false) && $service->is_express_available;

            return [
                'service' => $service,
                'garment_type_id' => $item['garment_type_id'] ?? null,
                'is_express' => $express,
                'quantity' => round((float) $item['quantity'], 2),
                'unit_price' => $service->unitPriceFor($express),
                'notes' => $item['notes'] ?? null,
            ];
        })->all();
    }

    private function organizationTaxRate(int $organizationId): ?float
    {
        return DB::table('organizations')->where('id', $organizationId)->value('tax_rate');
    }

    private function findByClientRequest(Branch $branch, string $clientRequestId): ?Order
    {
        return Order::query()
            ->where('branch_id', $branch->getKey())
            ->where('client_request_id', $clientRequestId)
            ->first();
    }

    private function isDuplicateKey(QueryException $exception): bool
    {
        return in_array($exception->getCode(), ['23000', '23505'], true);
    }
}

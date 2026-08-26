<?php

namespace App\Services\Orders;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Models\Order;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use App\Services\Payments\WalletService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Advances an order through its lifecycle and unwinds it on cancellation.
 *
 * The state machine is strict and one-directional. A cancellation reverses everything
 * idempotently under locks: the sale entry, any wallet payment refunded to the
 * customer, and (once subscriptions exist) the consumed quota.
 */
class OrderStatusService
{
    public function __construct(
        private readonly OrderAccountingService $accounting,
        private readonly WalletService $wallet,
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Move an order to a new status, recording the transition and running any
     * cancellation reversals.
     */
    public function transition(Order $order, OrderStatusEnum $target, ?int $userId = null): Order
    {
        $current = $order->status;

        abort_unless($current->canTransitionTo($target), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_status_transition'));

        DB::transaction(function () use ($order, $current, $target, $userId) {
            $order->forceFill(['status' => $target->value]);

            if ($target === OrderStatusEnum::Delivered) {
                $order->forceFill(['delivered_at' => now()]);
            }

            $order->save();

            $order->statusHistories()->create([
                'user_id' => $userId,
                'from_status' => $current->value,
                'to_status' => $target->value,
                'at' => now(),
            ]);

            $this->syncArchive($order);
        });

        if ($target === OrderStatusEnum::Cancelled) {
            $this->reverseOnCancel($order);
        }

        return $order->refresh();
    }

    /**
     * Archive an order once delivered and fully paid; reopen it if either ceases to
     * hold (a refund can make it unpaid again).
     */
    public function syncArchive(Order $order): void
    {
        $shouldArchive = $order->status === OrderStatusEnum::Delivered
            && $order->payment_status === PaymentStatusEnum::Paid;

        if ($shouldArchive && $order->archived_at === null) {
            $order->forceFill(['archived_at' => now()])->save();
        } elseif (! $shouldArchive && $order->archived_at !== null) {
            $order->forceFill(['archived_at' => null])->save();
        }
    }

    /*
    |--------------------------------------------------------------------------
    | Cancellation reversals (idempotent, best-effort)
    |--------------------------------------------------------------------------
    */
    private function reverseOnCancel(Order $order): void
    {
        // Each reversal is idempotent and isolated: a failure is reported but never
        // blocks the cancellation itself.
        $this->safely(fn () => $this->accounting->sync($order->refresh()));
        $this->safely(fn () => $this->refundWallet($order));
        // Subscription restore is wired when the subscriptions module lands (phase 5).
    }

    /**
     * Return wallet payments to the customer and post the matching liability move.
     *
     * A per-order advisory-style guard (the idempotency check on the refund entry)
     * makes a double click harmless: the second attempt finds the refund already
     * posted and does nothing.
     */
    private function refundWallet(Order $order): void
    {
        $order->loadMissing('payments', 'customer');

        $walletPaid = round(
            (float) $order->payments->where('method', PaymentMethodEnum::Wallet)->sum('amount'),
            2
        );

        if ($walletPaid <= 0 || $order->customer === null) {
            return;
        }

        $organizationId = $order->organization_id;

        DB::transaction(function () use ($order, $walletPaid, $organizationId) {
            // Idempotency: if the refund entry already exists, a prior cancel handled it.
            $alreadyRefunded = DB::table('journal_entries')
                ->where('organization_id', $organizationId)
                ->where('source', JournalSourceEnum::Order->value)
                ->where('ref_type', 'OrderWalletRefund')
                ->where('ref_id', (string) $order->getKey())
                ->exists();

            if ($alreadyRefunded) {
                return;
            }

            // The refund restores the deferred liability rather than paying cash, so
            // the wallet credit posts no entry and the entry is written here.
            $this->wallet->credit(
                $order->customer,
                $walletPaid,
                WalletTransactionTypeEnum::Refund,
                __('api.order_reversal_memo', ['order_no' => $order->order_no]),
                $order->getKey(),
                postAccounting: false,
            );

            $this->posting->post([
                'organization_id' => $organizationId,
                'source' => JournalSourceEnum::Order,
                'ref_type' => 'OrderWalletRefund',
                'ref_id' => $order->getKey(),
                'memo' => __('api.order_reversal_memo', ['order_no' => $order->order_no]),
                'branch_id' => $order->branch_id,
                'lines' => [
                    ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::AccountsReceivable)->getKey(), 'debit' => $walletPaid],
                    ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::DeferredRevenue)->getKey(), 'credit' => $walletPaid],
                ],
            ]);
        });
    }

    private function safely(callable $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $exception) {
            report($exception);
        }
    }
}

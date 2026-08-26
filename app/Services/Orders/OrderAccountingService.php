<?php

namespace App\Services\Orders;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Payments\PaymentMethodEnum;
use App\Models\Account;
use App\Models\Order;
use App\Models\Payment;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;

/**
 * Posts an order's ledger effect: the sale, each payment, and the cancellation
 * credit note.
 *
 * Every post is idempotent on its source reference, so this can be re-run safely —
 * which is what makes it a best-effort step after the sale commits and what lets a
 * backfill re-post anything that was missed.
 */
class OrderAccountingService
{
    public function __construct(
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Bring an order's ledger fully in step: post the sale (or its reversal when
     * cancelled) and any not-yet-posted payment.
     */
    public function sync(Order $order): void
    {
        $order->loadMissing('payments');

        if ($order->status === OrderStatusEnum::Cancelled) {
            $this->postReversal($order);
        } else {
            $this->postSale($order);
        }

        foreach ($order->payments as $payment) {
            $this->postPayment($order, $payment);
        }
    }

    /**
     * The sale: receivable for the gross, revenue for the net-of-tax, VAT payable,
     * and a contra-revenue discount.
     */
    public function postSale(Order $order): void
    {
        $grandTotal = (float) $order->grand_total;

        if ($grandTotal <= 0) {
            return;
        }

        $tax = (float) $order->tax_total;
        $discount = (float) $order->discount_total;
        $grossSales = round($grandTotal - $tax + $discount, 2);
        $organizationId = $order->organization_id;

        $lines = [
            ['account_id' => $this->account($organizationId, SystemAccountEnum::AccountsReceivable)->getKey(), 'debit' => $grandTotal],
            ['account_id' => $this->account($organizationId, SystemAccountEnum::Sales)->getKey(), 'credit' => $grossSales],
        ];

        if ($tax > 0) {
            $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::VatPayable)->getKey(), 'credit' => $tax];
        }
        if ($discount > 0) {
            $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::SalesDiscounts)->getKey(), 'debit' => $discount];
        }

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Order,
            'ref_type' => 'Order',
            'ref_id' => $order->getKey(),
            'date' => $order->created_at ?? now(),
            'memo' => __('api.order_sale_memo', ['order_no' => $order->order_no]),
            'branch_id' => $order->branch_id,
            'lines' => $lines,
        ]);
    }

    /**
     * A payment settles the receivable. The debit account depends on the method:
     * cash to Cash, card/transfer to Bank, wallet to Deferred Revenue (the liability
     * booked at top-up is now consumed).
     */
    public function postPayment(Order $order, Payment $payment): void
    {
        $amount = (float) $payment->amount;

        if ($amount <= 0) {
            return;
        }

        $organizationId = $order->organization_id;
        $debitAccount = match ($payment->method) {
            PaymentMethodEnum::Cash => SystemAccountEnum::Cash,
            PaymentMethodEnum::Wallet => SystemAccountEnum::DeferredRevenue,
            default => SystemAccountEnum::Bank,
        };

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Payment,
            'ref_type' => 'Payment',
            'ref_id' => $payment->getKey(),
            'date' => $payment->created_at ?? now(),
            'memo' => __('api.order_sale_memo', ['order_no' => $order->order_no]),
            'branch_id' => $order->branch_id,
            'lines' => [
                ['account_id' => $this->account($organizationId, $debitAccount)->getKey(), 'debit' => $amount],
                ['account_id' => $this->account($organizationId, SystemAccountEnum::AccountsReceivable)->getKey(), 'credit' => $amount],
            ],
        ]);
    }

    /**
     * The credit note reversing a cancelled sale: revenue and VAT are given back
     * together, matching ZATCA credit-note handling.
     */
    public function postReversal(Order $order): void
    {
        $grandTotal = (float) $order->grand_total;

        if ($grandTotal <= 0) {
            return;
        }

        $tax = (float) $order->tax_total;
        $discount = (float) $order->discount_total;
        $grossSales = round($grandTotal - $tax + $discount, 2);
        $organizationId = $order->organization_id;

        $lines = [
            ['account_id' => $this->account($organizationId, SystemAccountEnum::Sales)->getKey(), 'debit' => $grossSales],
            ['account_id' => $this->account($organizationId, SystemAccountEnum::AccountsReceivable)->getKey(), 'credit' => $grandTotal],
        ];

        if ($tax > 0) {
            $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::VatPayable)->getKey(), 'debit' => $tax];
        }
        if ($discount > 0) {
            $lines[] = ['account_id' => $this->account($organizationId, SystemAccountEnum::SalesDiscounts)->getKey(), 'credit' => $discount];
        }

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Order,
            'ref_type' => 'OrderReversal',
            'ref_id' => $order->getKey(),
            'date' => now(),
            'memo' => __('api.order_reversal_memo', ['order_no' => $order->order_no]),
            'branch_id' => $order->branch_id,
            'lines' => $lines,
        ]);
    }

    private function account(int $organizationId, SystemAccountEnum $key): Account
    {
        return $this->chart->systemAccount($organizationId, $key);
    }
}

<?php

namespace App\Services\Subscriptions;

use App\Enum\Accounting\JournalSourceEnum;
use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Orders\PaymentStatusEnum;
use App\Enum\Subscriptions\SubscriptionStatusEnum;
use App\Enum\Subscriptions\SubscriptionTypeEnum;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Accounting\ChartOfAccountsService;
use App\Services\Accounting\JournalPostingService;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settles a point-of-sale order against the customer's subscription.
 *
 * The one active in-period subscription is picked under a row lock so two concurrent
 * checkouts can never draw the same balance twice. A priced plan must already be paid.
 * Consumption writes the subscription id onto the order — the trace a later cancel would
 * need to restore the drawn quota — and recognises the deferred revenue the prepayment
 * created (Dr Deferred Revenue / Cr AR).
 */
class SubscriptionConsumptionService
{
    public function __construct(
        private readonly SubscriptionService $subscriptions,
        private readonly JournalPostingService $posting,
        private readonly ChartOfAccountsService $chart,
    ) {}

    /**
     * Draw the order's cost from the customer's subscription and mark it paid.
     *
     * Must run inside the order-creation transaction so the lock and the ledger post
     * share the sale's atomicity.
     */
    public function consume(Order $order, Customer $customer): void
    {
        $subscription = Subscription::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', SubscriptionStatusEnum::Active->value)
            ->where(fn ($query) => $query->whereNull('end_at')->orWhere('end_at', '>=', Carbon::now()))
            ->orderByDesc('start_at')
            ->lockForUpdate()
            ->first();

        abort_if($subscription === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.no_active_subscription'));

        $plan = $subscription->plan;

        // A priced plan is consumable only once its period has been collected.
        if ((float) $plan->price > 0 && ! $this->subscriptions->isPaid($subscription)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_not_paid'));
        }

        $remaining = $order->remaining();

        $this->drawDown($subscription, $plan->type, $order, $remaining);

        $order->forceFill([
            'paid_total' => round((float) $order->paid_total + $remaining, 2),
            'payment_status' => PaymentStatusEnum::Paid->value,
            'subscription_id' => $subscription->getKey(),
        ])->save();

        $this->recognise($order, $remaining);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function drawDown(Subscription $subscription, SubscriptionTypeEnum $type, Order $order, float $remaining): void
    {
        match ($type) {
            SubscriptionTypeEnum::PieceQuota => $this->drawPieces($subscription, $order),
            SubscriptionTypeEnum::PrepaidBalance => $this->drawBalance($subscription, $remaining),
            SubscriptionTypeEnum::UnlimitedService => null,
        };
    }

    private function drawPieces(Subscription $subscription, Order $order): void
    {
        $pieces = round((float) $order->items()->sum('quantity'), 2);
        $available = round((float) $subscription->remaining_quota, 2);

        if ($available < $pieces) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_quota_insufficient', ['remaining' => $available]));
        }

        $subscription->forceFill(['remaining_quota' => round($available - $pieces, 2)])->save();
    }

    private function drawBalance(Subscription $subscription, float $remaining): void
    {
        $available = round((float) $subscription->remaining_balance, 2);

        if ($available < $remaining) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.subscription_balance_insufficient', ['remaining' => $available]));
        }

        $subscription->forceFill(['remaining_balance' => round($available - $remaining, 2)])->save();
    }

    /**
     * Recognise the prepaid revenue: what sat as a deferred liability since the
     * subscription was collected now settles the order's receivable. Idempotent on the
     * order id.
     */
    private function recognise(Order $order, float $remaining): void
    {
        if ($remaining <= 0) {
            return;
        }

        $organizationId = $order->organization_id;

        $this->posting->post([
            'organization_id' => $organizationId,
            'source' => JournalSourceEnum::Payment,
            'ref_type' => 'SubscriptionConsume',
            'ref_id' => $order->getKey(),
            'memo' => __('api.subscription_consume_memo', ['order_no' => $order->order_no]),
            'branch_id' => $order->branch_id,
            'lines' => [
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::DeferredRevenue)->getKey(), 'debit' => $remaining],
                ['account_id' => $this->chart->systemAccount($organizationId, SystemAccountEnum::AccountsReceivable)->getKey(), 'credit' => $remaining],
            ],
        ]);
    }
}

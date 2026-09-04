<?php

namespace App\Services\Catalog;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Subscription;
use App\Services\Payments\WalletService;
use App\Services\Subscriptions\SubscriptionConsumptionService;
use Illuminate\Database\Eloquent\Builder;

class CustomerOverviewService
{
    private const ORDERS_PER_PAGE = 20;

    private const OUTSTANDING_PER_PAGE = 10;

    private const SUBSCRIPTIONS_PER_PAGE = 20;

    public function __construct(
        private readonly CustomerContextService $context,
        private readonly WalletService $wallet,
        private readonly SubscriptionConsumptionService $subscriptions,
    ) {}

    /**
     * Data needed for the customer's first screen paint. Follow-up filters keep using
     * their focused endpoints; this removes four duplicate HTTP bootstraps on entry.
     *
     * @param  array<int, int>  $branchIds
     * @return array<string, mixed>
     */
    public function build(Customer $customer, array $branchIds): array
    {
        $activeSubscription = $this->subscriptions->activeFor($customer)->with('plan')->first();
        $orders = $this->orders($customer, $branchIds);

        return [
            'active_subscription' => $activeSubscription,
            'subscription_usable' => $this->subscriptions->isUsable($activeSubscription),
            'stats' => $this->context->stats($customer),
            'loyalty' => $this->context->loyalty($customer),
            'wallet_transactions' => $this->wallet->history($customer),
            'orders' => (clone $orders)->paginate(self::ORDERS_PER_PAGE, page: 1),
            'outstanding_orders' => (clone $orders)->outstanding()
                ->paginate(self::OUTSTANDING_PER_PAGE, page: 1),
            'subscriptions' => Subscription::query()
                ->inBranches($branchIds)
                ->where('customer_id', $customer->getKey())
                ->with('plan:id,name,cycle,type,price')
                ->orderByDesc('end_at')
                ->paginate(self::SUBSCRIPTIONS_PER_PAGE, page: 1),
        ];
    }

    /** @param array<int, int> $branchIds */
    private function orders(Customer $customer, array $branchIds): Builder
    {
        return Order::query()
            ->inBranches($branchIds)
            ->where('customer_id', $customer->getKey())
            ->select([
                'id', 'branch_id', 'customer_id', 'order_no', 'status', 'payment_status',
                'grand_total', 'paid_total', 'created_at',
            ])
            ->latest('id');
    }
}

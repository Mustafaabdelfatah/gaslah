<?php

namespace App\Http\Controllers\API\Tenancy\Subscriptions;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Subscriptions\PaySubscriptionRequest;
use App\Http\Requests\Subscriptions\PurchaseSubscriptionRequest;
use App\Http\Resources\Subscriptions\SubscriptionResource;
use App\Models\Customer;
use App\Models\Subscription;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\AbstractPaginator;

class SubscriptionController extends TenantController
{
    public function __construct(private readonly SubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = Subscription::query()
            ->inBranches($this->readBranchIds())
            // price rides along because the listing is where a period is collected.
            ->with(['customer:id,name,phone', 'plan:id,name,cycle,type,price'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->orderByDesc('end_at');

        $result = wrapPaginate($query);
        $subscriptions = $result instanceof AbstractPaginator
            ? $result->getCollection()
            : $result;

        $this->subscriptions->annotatePaid($subscriptions);
        $serialized = $subscriptions->map(
            fn (Subscription $subscription) => (new SubscriptionResource($subscription))->resolve($request),
        );

        if ($result instanceof AbstractPaginator) {
            $result->setCollection($serialized);
        } else {
            $result = $serialized;
        }

        return successResponse($result);
    }

    /**
     * Sell a plan to a customer. Buying only creates the subscription with its prepaid
     * balance; the period's price is collected separately by pay().
     */
    public function store(PurchaseSubscriptionRequest $request): JsonResponse
    {

        $subscription = $this->subscriptions->purchase($request->plan(), $request->customer());

        return successResponse(
            new SubscriptionResource($subscription->load('customer:id,name,phone', 'plan')),
            __('api.created_success'),
            201,
        );
    }

    /**
     * Collect a subscription period's price and issue a receipt.
     *
     * Per the spec any staff member may collect — this is deliberately not
     * manager-gated, so a cashier can take the payment.
     */
    public function pay(PaySubscriptionRequest $request, Subscription $subscription): JsonResponse
    {
        $this->staff();
        $this->assertOwned($subscription);

        $subscription->load('plan', 'customer');

        $result = $this->subscriptions->pay($subscription, $request->method(), $request->otpToken());

        return successResponse($result, __('api.subscription_collected'));
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Subscriptions;

use App\Enum\Payments\PaymentMethodEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Customer;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Services\Subscriptions\SubscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends TenantController
{
    private const FEATURE = 'subscriptions';

    public function __construct(private readonly SubscriptionService $subscriptions)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        $query = Subscription::query()
            ->inBranches($this->readBranchIds())
            ->with(['customer:id,name,phone', 'plan:id,name,cycle,type'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->input('customer_id')))
            ->orderByDesc('end_at');

        return successResponse(wrapPaginate($query));
    }

    /**
     * Sell a plan to a customer. Buying only creates the subscription with its prepaid
     * balance; the period's price is collected separately by pay().
     */
    public function store(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'plan_id' => ['required', 'integer'],
        ]);

        $plan = SubscriptionPlan::query()->forOrganization($this->organizationId())->find($data['plan_id']);
        abort_if($plan === null, 404, __('api.subscription_plan_not_found'));

        $customer = Customer::query()->forOrganization($this->organizationId())->find($data['customer_id']);
        abort_if($customer === null, 404, __('api.record_not_found'));

        $subscription = $this->subscriptions->purchase($plan, $customer);

        return successResponse($subscription->load('customer:id,name,phone', 'plan:id,name,cycle,type'), __('api.created_success'), 201);
    }

    /**
     * Collect a subscription period's price and issue a receipt.
     *
     * Per the spec any staff member may collect — this is deliberately not
     * manager-gated, so a cashier can take the payment.
     */
    public function pay(Request $request, Subscription $subscription): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($subscription);

        $data = $request->validate([
            'method' => ['required', 'in:cash,card,transfer,wallet'],
            'otp_token' => ['nullable', 'string'],
        ]);

        $subscription->load('plan', 'customer');

        $result = $this->subscriptions->pay(
            $subscription,
            PaymentMethodEnum::from($data['method']),
            $data['otp_token'] ?? null,
        );

        return successResponse($result, __('api.subscription_collected'));
    }

    private function assertOwned(Subscription $subscription): void
    {
        abort_unless($subscription->organization_id === $this->organizationId(), 404, __('api.record_not_found'));
    }
}

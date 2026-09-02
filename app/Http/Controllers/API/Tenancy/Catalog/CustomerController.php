<?php

namespace App\Http\Controllers\API\Tenancy\Catalog;

use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Filters\Catalog\CustomerFilter;
use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Catalog\CustomerRequest;
use App\Http\Requests\Catalog\WalletTopupRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Resources\Catalog\CustomerResource;
use App\Models\Customer;
use App\Services\Catalog\CustomerContextService;
use App\Services\Payments\WalletService;
use App\Services\Subscriptions\SubscriptionConsumptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends TenantController
{
    public function __construct(
        private readonly WalletService $wallet,
        private readonly SubscriptionConsumptionService $subscriptions,
        private readonly CustomerContextService $context,
    ) {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {

        $query = app(Pipeline::class)
            ->send(Customer::query()->forOrganization($this->organizationId()))
            ->through([CustomerFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, CustomerResource::class));
    }

    public function store(CustomerRequest $request): JsonResponse
    {

        $customer = Customer::create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
        ]);

        return successResponse(new CustomerResource($customer), __('api.created_success'), Response::HTTP_CREATED);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        // The till reads this to decide whether it may offer a subscription payment,
        // so the answer travels with the customer rather than being guessed. It is
        // deliberately not on the listing: one query per row would be an N+1 for a
        // figure only the counter needs.
        $subscription = $this->subscriptions->activeFor($customer)->with('plan')->first();

        return successResponse(
            (new CustomerResource($customer))
                ->withSubscription($subscription, $this->subscriptions->isUsable($subscription))
                ->withContext($this->context->stats($customer), $this->context->loyalty($customer)),
        );
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        $customer->update($request->validated());

        return successResponse(new CustomerResource($customer->refresh()), __('api.updated_success'));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        // A customer with order history is never deleted: orders reference them and the
        // wallet holds their money.
        abort_if($customer->orders()->exists(), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.customer_has_orders'));

        $customer->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    /**
     * Top up a customer's wallet. The movement and its ledger entry go through the wallet
     * service so the balance stays locked and the books stay in step.
     */
    public function walletTopup(WalletTopupRequest $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        $result = $this->wallet->credit(
            $customer,
            $request->amount(),
            WalletTransactionTypeEnum::Topup,
            $request->note() ?? __('api.wallet_topup_memo', ['name' => $customer->name]),
            fundingAccount: $request->fundingAccount(),
            collectedAtBranchId: $this->writeBranchId(),
        );

        $receipt = [
            'receipt_no' => 'WALLET-'.str_pad((string) $result['transaction_id'], 8, '0', STR_PAD_LEFT),
            'customer_name' => $customer->name,
            'customer_phone' => $customer->phone,
            'amount' => $request->amount(),
            'method' => $request->string('method')->toString(),
            'balance_after' => $result['balance'],
            'created_at' => Carbon::now(),
        ];

        return successResponse(
            [...$result, 'receipt' => $receipt],
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }

    public function walletTransactions(Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        return successResponse([
            'balance' => $customer->wallet_balance,
            'transactions' => $this->wallet->history($customer),
        ]);
    }
}

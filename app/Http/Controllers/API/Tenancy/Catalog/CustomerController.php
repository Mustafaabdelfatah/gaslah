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
use App\Services\Payments\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class CustomerController extends TenantController
{
    public function __construct(private readonly WalletService $wallet)
    {
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

        return successResponse(new CustomerResource($customer));
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
        );

        return successResponse($result, __('api.updated_success'));
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

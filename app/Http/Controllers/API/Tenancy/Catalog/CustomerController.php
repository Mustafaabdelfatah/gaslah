<?php

namespace App\Http\Controllers\API\Tenancy\Catalog;

use App\Enum\Accounting\SystemAccountEnum;
use App\Enum\Payments\WalletTransactionTypeEnum;
use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Catalog\CustomerRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Customer;
use App\Services\Payments\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerController extends TenantController
{
    public function __construct(private readonly WalletService $wallet)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);

        $query = Customer::query()
            ->forOrganization($this->organizationId())
            ->when($request->filled('type'), fn ($q) => $q->where('type', $request->input('type')))
            ->when($request->filled('search'), function ($q) use ($request) {
                $term = '%'.$request->input('search').'%';
                $q->where(fn ($sub) => $sub->where('name', 'like', $term)->orWhere('phone', 'like', $term));
            })
            ->latest('updated_at');

        return successResponse(wrapPaginate($query));
    }

    public function store(CustomerRequest $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertPhoneIsFree($request->input('phone'));

        $customer = Customer::query()->create([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'branch_id' => $this->writeBranchId(),
            'type' => $request->input('type', 'regular'),
        ]);

        return successResponse($customer, __('api.created_success'), 201);
    }

    public function show(Customer $customer): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertOwned($customer);

        return successResponse($customer);
    }

    public function update(CustomerRequest $request, Customer $customer): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertOwned($customer);
        $this->assertPhoneIsFree($request->input('phone'), $customer->getKey());

        $customer->update($request->validated());

        return successResponse($customer->refresh(), __('api.updated_success'));
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertOwned($customer);

        // A customer with order history is never deleted (orders reference them, and
        // the wallet holds their money). The orders relation lands in the POS module.
        if ($this->hasOrders($customer)) {
            abort(422, __('api.customer_has_orders'));
        }

        $customer->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    /**
     * Top up a customer's wallet. The movement and its ledger entry go through the
     * wallet service so the balance stays locked and the books stay in step.
     */
    public function walletTopup(Request $request, Customer $customer): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertOwned($customer);

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['nullable', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        $funding = ($data['method'] ?? 'cash') === 'bank'
            ? SystemAccountEnum::Bank
            : SystemAccountEnum::Cash;

        $result = $this->wallet->credit(
            $customer,
            (float) $data['amount'],
            WalletTransactionTypeEnum::Topup,
            $data['note'] ?? __('api.wallet_topup_memo', ['name' => $customer->name]),
            fundingAccount: $funding,
        );

        return successResponse($result, __('api.updated_success'));
    }

    public function walletTransactions(Customer $customer): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::CustomersManage);
        $this->assertOwned($customer);

        return successResponse([
            'balance' => $customer->wallet_balance,
            'transactions' => $this->wallet->history($customer),
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    private function assertPhoneIsFree(?string $phone, ?int $ignoreId = null): void
    {
        if ($phone === null) {
            return;
        }

        $exists = Customer::query()
            ->forOrganization($this->organizationId())
            ->where('phone', $phone)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists();

        abort_if($exists, 422, __('api.phone_already_used'));
    }

    private function hasOrders(Customer $customer): bool
    {
        // The orders table arrives with the POS module; until then no customer has any.
        return Schema::hasTable('orders')
            && DB::table('orders')->where('customer_id', $customer->getKey())->exists();
    }
}

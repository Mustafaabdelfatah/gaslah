<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Enum\Messaging\WaEventEnum;
use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Order;
use App\Services\Messaging\WaService;
use App\Services\Orders\PosOtpService;
use App\Services\Orders\PosService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosController extends TenantController
{
    public function __construct(
        private readonly PosService $pos,
        private readonly PosOtpService $otp,
        private readonly WaService $wa,
    ) {
        parent::__construct();
    }

    /**
     * Create a point-of-sale order and settle its optional payment.
     */
    public function store(StoreOrderRequest $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::PosCheckout);
        $this->requireActiveSubscription();

        $branch = Branch::query()
            ->where('organization_id', $this->organizationId())
            ->findOrFail($this->writeBranchId());

        $order = $this->pos->create(
            $this->organizationId(),
            $branch,
            $this->staff()->getKey(),
            $request->validated(),
        );

        // Best-effort ledger sync after the sale commits; never blocks the sale.
        $this->pos->postAccounting($order);

        $this->notifyCreated($order);

        return successResponse($order, __('api.created_success'), 201);
    }

    /**
     * Send a wallet-consent code to the customer's phone.
     */
    public function otpRequest(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::PosCheckout);
        $data = $request->validate(['customer_id' => ['required', 'integer']]);

        $customer = $this->ownedCustomer($data['customer_id']);

        return successResponse($this->otp->request($customer));
    }

    /**
     * Verify a code and return a one-shot consent proof to attach to a wallet payment.
     */
    public function otpVerify(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::PosCheckout);
        $data = $request->validate([
            'customer_id' => ['required', 'integer'],
            'code' => ['required', 'string'],
        ]);

        $customer = $this->ownedCustomer($data['customer_id']);

        return successResponse($this->otp->verify($customer, $data['code']));
    }

    private function ownedCustomer(int $customerId): Customer
    {
        $customer = Customer::query()->forOrganization($this->organizationId())->find($customerId);
        abort_if($customer === null, 404, __('api.record_not_found'));

        return $customer;
    }

    /**
     * Fire the order-created WhatsApp event (best-effort).
     */
    private function notifyCreated(Order $order): void
    {
        $order->loadMissing('customer:id,name,phone');

        $this->wa->trigger($order->organization_id, WaEventEnum::OrderCreated, $order->customer?->phone, [
            'name' => $order->customer?->name,
            'orderNo' => $order->order_no,
            'total' => (string) $order->grand_total,
            'org' => $this->organization()->name,
        ], [
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
        ]);
    }
}

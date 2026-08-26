<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use App\Services\Payments\PayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class OrderController extends TenantController
{
    public function __construct(
        private readonly OrderStatusService $status,
        private readonly PayTokenService $payTokens,
    ) {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->staff();

        $query = Order::query()
            ->inBranches($this->readBranchIds())
            ->with('customer:id,name,phone')
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->input('status')))
            ->when($request->filled('payment_status'), fn ($q) => $q->where('payment_status', $request->input('payment_status')))
            ->latest('id');

        return successResponse(wrapPaginate($query));
    }

    public function show(Order $order): JsonResponse
    {
        $this->staff();
        $this->assertReadable($order);

        return successResponse($order->load('items', 'payments', 'customer', 'statusHistories'));
    }

    /**
     * Advance the order's workflow status (and run any cancellation reversals).
     */
    public function updateStatus(Request $request, Order $order): JsonResponse
    {
        $this->staff();
        $this->assertReadable($order);

        $data = $request->validate(['status' => ['required', new Enum(OrderStatusEnum::class)]]);
        $target = OrderStatusEnum::from($data['status']);

        $order = $this->status->transition($order, $target, $this->staff()->getKey());

        return successResponse($order->load('items', 'payments'), __('api.updated_success'));
    }

    /**
     * Mint a public payment link for an unpaid order.
     */
    public function paymentLink(Order $order): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::OrdersManage);
        $this->assertReadable($order);

        abort_if($order->status === OrderStatusEnum::Cancelled, 422, __('api.order_cancelled'));
        abort_if($order->remaining() <= 0, 422, __('api.order_fully_paid'));

        $token = $this->payTokens->mint($order->getKey(), time());
        $path = '/pay/'.$token;

        return successResponse([
            'token' => $token,
            'path' => $path,
            'url' => rtrim((string) config('services.payment.web_url'), '/').$path,
        ]);
    }

    /**
     * An order is readable when it belongs to a branch in the caller's read scope.
     */
    private function assertReadable(Order $order): void
    {
        abort_unless(in_array($order->branch_id, $this->readBranchIds(), true), 404, __('api.record_not_found'));
    }
}

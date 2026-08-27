<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Filters\Global\OrderByFilter;
use App\Filters\Orders\OrderFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderStatusService;
use App\Services\Payments\PayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

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

        $query = app(Pipeline::class)
            ->send(Order::query()->inBranches($this->readBranchIds())->with('customer:id,name,phone'))
            ->through([OrderFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, OrderResource::class));
    }

    public function show(Order $order): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($order);

        return successResponse(new OrderResource(
            $order->load('items.service:id,name', 'payments', 'customer', 'statusHistories'),
        ));
    }

    /**
     * Advance the order's workflow status (and run any cancellation reversals).
     */
    public function updateStatus(UpdateOrderStatusRequest $request, Order $order): JsonResponse
    {
        $staff = $this->staff();
        $this->assertInReadScope($order);

        $order = $this->status->transition($order, $request->status(), $staff->getKey());

        return successResponse(
            new OrderResource($order->load('items.service:id,name', 'payments')),
            __('api.updated_success'),
        );
    }

    /**
     * Mint a public payment link for an unpaid order.
     */
    public function paymentLink(Order $order): JsonResponse
    {
        $this->assertInReadScope($order);

        abort_if($order->status === OrderStatusEnum::Cancelled, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_cancelled'));
        abort_if($order->remaining() <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_fully_paid'));

        $token = $this->payTokens->mint($order->getKey(), time());
        $path = '/pay/'.$token;

        return successResponse([
            'token' => $token,
            'path' => $path,
            'url' => rtrim((string) config('services.payment.web_url'), '/').$path,
        ]);
    }
}

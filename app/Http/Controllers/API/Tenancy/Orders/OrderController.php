<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Enum\Orders\OrderStatusEnum;
use App\Filters\Global\OrderByFilter;
use App\Filters\Orders\OrderFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Orders\CollectOrderPaymentRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Http\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Services\Orders\OrderCollectionService;
use App\Services\Orders\OrderStatusService;
use App\Services\Payments\PayTokenService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class OrderController extends TenantController
{
    /**
     * The board's columns, in the order work actually flows through them.
     */
    private const BOARD_COLUMNS = [
        OrderStatusEnum::Received,
        OrderStatusEnum::Processing,
        OrderStatusEnum::Ready,
        OrderStatusEnum::Delivered,
    ];

    /**
     * A ceiling per column. A shop with more than this in one column has a problem the
     * board cannot help with, and an unbounded query would take the screen down with it.
     */
    private const BOARD_CAP = 200;

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

    /**
     * The work board: every basket still moving through the shop, by column.
     *
     * Deliberately not paginated — a board that hid a basket on page two would be
     * worse than no board, since the whole point is that nothing is forgotten. It is
     * bounded instead by what belongs on it: only live work, and only today's
     * completions, since a delivered basket stops being the floor's problem.
     */
    public function board(): JsonResponse
    {
        $this->staff();

        $columns = [];

        foreach (self::BOARD_COLUMNS as $status) {
            $query = Order::query()
                ->inBranches($this->readBranchIds())
                ->where('status', $status->value)
                ->with('customer:id,name,phone');

            // Delivered is a record of what just left, not a queue.
            if ($status === OrderStatusEnum::Delivered) {
                $query->whereDate('created_at', '>=', now()->toDateString());
            }

            $columns[$status->value] = OrderResource::collection(
                $query->orderBy('due_at')->orderByDesc('id')->limit(self::BOARD_CAP)->get(),
            );
        }

        return successResponse([
            'columns' => $columns,
            'total' => array_sum(array_map(static fn ($column) => $column->count(), $columns)),
        ]);
    }

    /**
     * Find one basket by the barcode on its ticket, or by its order number.
     *
     * A scanner types the code and hits Enter, so this answers a plain 404 when
     * nothing matches rather than an empty list the station would have to interpret.
     */
    public function scan(string $code): JsonResponse
    {
        $this->staff();

        $code = trim($code);

        $order = Order::query()
            ->inBranches($this->readBranchIds())
            ->where(fn ($q) => $q->where('barcode', $code)->orWhere('order_no', $code))
            ->with('customer:id,name,phone', 'items.service:id,name')
            ->first();

        abort_if($order === null, Response::HTTP_NOT_FOUND, __('api.not_found'));

        return successResponse(new OrderResource($order));
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

        // The customer rides along because the board drops this row straight into
        // its new column — without it the card would lose its name on every move.
        return successResponse(
            new OrderResource($order->load('items.service:id,name', 'payments', 'customer:id,name,phone')),
            __('api.updated_success'),
        );
    }

    /**
     * Mint a public payment link for an unpaid order.
     */
    /**
     * Collect a counter payment on an existing order — the partial settled later, the
     * deferred debt the customer came back to clear.
     */
    public function collectPayment(CollectOrderPaymentRequest $request, Order $order, OrderCollectionService $collections): JsonResponse
    {
        $this->assertOwned($order);

        $order = $collections->collect($order, $request->method(), $request->amount(), $request->reference());

        return successResponse(
            new OrderResource($order->load('items.service:id,name', 'payments', 'customer:id,name,phone')),
            __('api.updated_success'),
        );
    }

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

<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Enum\Messaging\WaEventEnum;
use App\Enum\Messaging\WaMessageStatusEnum;
use App\Enum\Orders\OrderStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Orders\SendOrderNotificationRequest;
use App\Models\Order;
use App\Services\Messaging\WaService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class OrderNotificationController extends TenantController
{
    public function __construct(private readonly WaService $messaging)
    {
        parent::__construct();
    }

    public function store(SendOrderNotificationRequest $request, Order $order): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($order);

        $order->loadMissing('customer:id,name,phone');
        abort_if($order->customer === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.order_customer_missing'));
        abort_if(blank($order->customer->phone), Response::HTTP_UNPROCESSABLE_ENTITY, __('api.customer_phone_missing'));

        $organization = $this->organization();
        $message = $this->messaging->queue([
            'organization_id' => $organization->getKey(),
            'branch_id' => $order->branch_id,
            'customer_id' => $order->customer_id,
            'order_id' => $order->getKey(),
            'to_phone' => $order->customer->phone,
            'channel' => $request->channel(),
            'category' => WaEventEnum::Invoice->category(),
            'event_key' => WaEventEnum::Invoice->value,
            'body' => $this->messageBody($order, $organization->name),
        ])->refresh();

        $sent = ! in_array($message->status, [
            WaMessageStatusEnum::Blocked,
            WaMessageStatusEnum::Failed,
        ], true);

        return successResponse([
            'sent' => $sent,
            'channel' => $request->channel(),
            'status' => $message->status->value,
            'reason' => $sent ? null : $message->error,
        ]);
    }

    private function messageBody(Order $order, string $organizationName): string
    {
        $status = match ($order->status) {
            OrderStatusEnum::Received => 'تم الاستلام',
            OrderStatusEnum::Processing => 'قيد المعالجة',
            OrderStatusEnum::Ready => 'جاهز للاستلام',
            OrderStatusEnum::Delivered => 'تم التسليم',
            OrderStatusEnum::Cancelled => 'ملغي',
        };

        return implode("\n", [
            $organizationName,
            "عزيزنا {$order->customer->name}،",
            "فاتورة طلبكم رقم {$order->order_no}",
            "الحالة: {$status}",
            'الإجمالي: '.number_format((float) $order->grand_total, 2).' ﷼ (شامل ضريبة القيمة المضافة)',
            'شكراً لتعاملكم معنا.',
        ]);
    }
}

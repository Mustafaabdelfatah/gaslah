<?php

namespace App\Http\Controllers\API\Portal;

use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrgAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The customer portal: profile and wallet (read-only), orders, order detail, and
 * addresses. Every query is scoped to the authenticated customer; anything they do not
 * own returns 404, never revealing it exists.
 */
class PortalController extends PortalBaseController
{
    private const MAX_ORDERS = 50;

    public function me(): JsonResponse
    {
        $customer = $this->customer();

        return successResponse([
            'id' => $customer->getKey(),
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'type' => $customer->type,
            'wallet_balance' => round((float) $customer->wallet_balance, 2),
        ]);
    }

    public function orders(): JsonResponse
    {
        $orders = Order::query()
            ->where('customer_id', $this->customer()->getKey())
            ->latest('id')
            ->limit(self::MAX_ORDERS)
            ->get();

        $data = $orders->map(fn (Order $order) => [
            'id' => $order->getKey(),
            'order_no' => $order->order_no,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'grand_total' => round((float) $order->grand_total, 2),
            'paid_total' => round((float) $order->paid_total, 2),
            'remaining' => $order->remaining(),
            'created_at' => $order->created_at,
            'due_at' => $order->due_at,
        ]);

        return successResponse($data);
    }

    public function order(int $id): JsonResponse
    {
        $order = Order::query()
            ->where('customer_id', $this->customer()->getKey())
            ->with(['branch:id,name', 'items.service:id,name', 'items.garmentType:id,name', 'payments'])
            ->find($id);

        abort_if($order === null, 404, __('api.order_not_found'));

        return successResponse([
            'id' => $order->getKey(),
            'order_no' => $order->order_no,
            'status' => $order->status->value,
            'payment_status' => $order->payment_status->value,
            'branch_name' => $order->branch?->name,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->getKey(),
                'name' => $this->itemName($item),
                'quantity' => round((float) $item->quantity, 2),
                'unit_price' => round((float) $item->unit_price, 2),
                'line_total' => round((float) $item->line_total, 2),
            ]),
            'payments' => $order->payments->sortBy('id')->values()->map(fn ($payment) => [
                'id' => $payment->getKey(),
                'method' => $payment->method->value,
                'amount' => round((float) $payment->amount, 2),
                'created_at' => $payment->created_at,
            ]),
            'subtotal' => round((float) $order->subtotal, 2),
            'discount_total' => round((float) $order->discount_total, 2),
            'tax_total' => round((float) $order->tax_total, 2),
            'grand_total' => round((float) $order->grand_total, 2),
            'paid_total' => round((float) $order->paid_total, 2),
            'remaining' => $order->remaining(),
            'notes' => $order->notes,
            'due_at' => $order->due_at,
            'created_at' => $order->created_at,
        ]);
    }

    public function addresses(): JsonResponse
    {
        $addresses = CustomerAddress::query()
            ->where('customer_id', $this->customer()->getKey())
            ->orderByDesc('is_default')
            ->latest('id')
            ->get(['id', 'label', 'district', 'street', 'details', 'is_default']);

        return successResponse($addresses);
    }

    public function storeAddress(Request $request): JsonResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'label' => ['required', 'string', 'max:40'],
            'district' => ['nullable', 'string', 'max:80'],
            'street' => ['nullable', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:200'],
            'is_default' => ['nullable', 'boolean'],
        ]);

        $makeDefault = (bool) ($data['is_default'] ?? false);

        // Setting a default must clear the others in the same transaction, so we never
        // end with two defaults or none.
        $address = DB::transaction(function () use ($customer, $data, $makeDefault) {
            if ($makeDefault) {
                CustomerAddress::query()->where('customer_id', $customer->getKey())->update(['is_default' => false]);
            }

            return CustomerAddress::query()->create([
                'customer_id' => $customer->getKey(),
                'label' => $data['label'],
                'district' => $data['district'] ?? null,
                'street' => $data['street'] ?? null,
                'details' => $data['details'] ?? null,
                'is_default' => $makeDefault,
            ]);
        });

        return successResponse($address, __('api.created_success'), 201);
    }

    /**
     * The active announcements of the customer's organization (portal carousel).
     */
    public function announcements(): JsonResponse
    {
        $announcements = OrgAnnouncement::query()
            ->forOrganization($this->customer()->organization_id)
            ->where('is_active', true)
            ->latest('id')
            ->limit(20)
            ->get(['id', 'title', 'body', 'image_url']);

        return successResponse($announcements);
    }

    public function destroyAddress(int $id): JsonResponse
    {
        $deleted = CustomerAddress::query()
            ->where('customer_id', $this->customer()->getKey())
            ->whereKey($id)
            ->delete();

        return successResponse(['deleted' => $deleted > 0], __('api.deleted_success'));
    }

    private function itemName(OrderItem $item): string
    {
        $service = (string) ($item->service?->name ?? '');
        $garment = $item->garmentType?->name;

        if ($service === '') {
            return __('api.service');
        }

        return $garment ? "{$service} ({$garment})" : $service;
    }
}

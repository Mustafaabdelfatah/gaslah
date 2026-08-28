<?php

namespace App\Http\Controllers\API\Portal;

use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Portal\StoreCustomerAddressRequest;
use App\Http\Resources\Portal\CustomerAddressResource;
use App\Http\Resources\Portal\PortalAnnouncementResource;
use App\Http\Resources\Portal\PortalOrderResource;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrgAnnouncement;
use App\Services\Portal\CustomerAddressService;
use Illuminate\Http\JsonResponse;

/**
 * The customer portal: profile and wallet (read-only), orders, order detail, and
 * addresses. Every query is scoped to the authenticated customer; anything they do not
 * own returns 404, never revealing it exists.
 */
class PortalController extends PortalBaseController
{
    public function __construct(private readonly CustomerAddressService $addresses)
    {
        parent::__construct();
    }

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

    public function orders(PageRequest $request): JsonResponse
    {
        $query = Order::query()
            ->where('customer_id', $this->customer()->getKey())
            ->latest('id');

        return successResponse(wrapPaginate($query, PortalOrderResource::class));
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

        return successResponse(CustomerAddressResource::collection($addresses));
    }

    public function storeAddress(StoreCustomerAddressRequest $request): JsonResponse
    {
        $customer = $this->customer();

        $address = $this->addresses->store($customer, $request->validated(), $request->shouldBecomeDefault());

        return successResponse(new CustomerAddressResource($address), __('api.created_success'), 201);
    }

    /**
     * The active announcements of the customer's organization (portal carousel).
     */
    public function announcements(): JsonResponse
    {
        $query = OrgAnnouncement::query()
            ->forOrganization($this->customer()->organization_id)
            ->where('is_active', true)
            ->latest('id');

        return successResponse(wrapPaginate($query, PortalAnnouncementResource::class));
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

<?php

namespace App\Http\Controllers\API\Portal;

use App\Enum\Delivery\DeliverySourceEnum;
use App\Models\Branch;
use App\Models\DeliveryRequest;
use App\Models\Order;
use App\Services\Delivery\DeliveryRequestService;
use App\Services\Delivery\DeliveryService;
use App\Services\Delivery\DeliverySettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The portal delivery surface: the customer requests, tracks, and approves — never
 * prices, assigns, or moves money. Fees come from the organization's pricing.
 */
class PortalDeliveryController extends PortalBaseController
{
    private const MAX_REQUESTS = 50;

    public function __construct(
        private readonly DeliverySettingsService $settings,
        private readonly DeliveryRequestService $requests,
        private readonly DeliveryService $delivery,
    ) {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $requests = DeliveryRequest::query()
            ->where('customer_id', $this->customer()->getKey())
            ->with(['driver:id,name,phone', 'order:id,order_no'])
            ->latest('id')
            ->limit(self::MAX_REQUESTS)
            ->get();

        return successResponse($requests->map(fn (DeliveryRequest $request) => $this->present($request)));
    }

    public function store(Request $request): JsonResponse
    {
        $customer = $this->customer();

        $data = $request->validate([
            'type' => ['required', 'in:pickup,delivery,both'],
            'address' => ['required', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:500'],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
            'order_id' => ['nullable', 'integer'],
            'lat' => ['nullable', 'numeric', 'between:-90,90'],
            'lng' => ['nullable', 'numeric', 'between:-180,180'],
        ]);

        $organizationId = $customer->organization_id;
        $settings = $this->settings->resolve($organizationId);

        // Portal ordering must be enabled, and at least one driver-backed method.
        abort_unless($this->settings->workflow($settings, 'portalOrdering'), 403, __('api.delivery_portal_ordering_disabled'));
        abort_unless(
            $this->settings->methodEnabled($settings, 'selfDelivery') || $this->settings->methodEnabled($settings, 'platformDriver'),
            422,
            __('api.delivery_no_driver_method'),
        );

        if (isset($data['order_id'])) {
            $owns = Order::query()->where('customer_id', $customer->getKey())->whereKey($data['order_id'])->exists();
            abort_unless($owns, 404, __('api.order_not_found'));
        }

        $branchId = $customer->branch_id ?? Branch::query()->where('organization_id', $organizationId)->value('id');
        abort_if($branchId === null, 422, __('api.delivery_no_driver_method'));

        // The portal never picks a zone; fees always come from self pricing.
        $created = $this->requests->createRequests(
            $organizationId,
            $branchId,
            $customer,
            $data['type'],
            null,
            $data,
            $settings,
            DeliverySourceEnum::Portal,
            null,
        );

        return successResponse($created->map(fn (DeliveryRequest $r) => $this->present($r)), __('api.created_success'), 201);
    }

    public function approveInvoice(int $id): JsonResponse
    {
        $request = DeliveryRequest::query()
            ->where('customer_id', $this->customer()->getKey())
            ->find($id);

        abort_if($request === null, 404, __('api.record_not_found'));
        abort_unless($request->invoice_approval_required, 422, __('api.delivery_approval_not_required'));

        $request->forceFill(['invoice_approved_at' => Carbon::now()])->save();
        $this->delivery->recordHistory($request, $request->status, $request->status, null, __('api.delivery_invoice_approved'));

        return successResponse($this->present($request->refresh()));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(DeliveryRequest $request): array
    {
        return [
            'id' => $request->getKey(),
            'type' => $request->type->value,
            'status' => $request->status->value,
            'fee' => round((float) $request->fee, 2),
            'address' => $request->address,
            'scheduled_at' => $request->scheduled_at,
            'created_at' => $request->created_at,
            'arrived_at' => $request->arrived_at,
            'invoice_approval_required' => $request->invoice_approval_required,
            'invoice_approved_at' => $request->invoice_approved_at,
            'driver_name' => $request->driver?->name,
            'order_no' => $request->order?->order_no,
            'order_id' => $request->order_id,
        ];
    }
}

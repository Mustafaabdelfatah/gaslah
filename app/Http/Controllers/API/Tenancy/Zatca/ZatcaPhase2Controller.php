<?php

namespace App\Http\Controllers\API\Tenancy\Zatca;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Order;
use App\Models\ZatcaInvoice;
use App\Services\Zatca\ZatcaInvoiceService;
use Illuminate\Http\JsonResponse;

/**
 * ZATCA Phase 2 — generate (idempotent) and read the stored UBL invoice for an order.
 */
class ZatcaPhase2Controller extends TenantController
{
    public function __construct(private readonly ZatcaInvoiceService $invoices)
    {
        parent::__construct();
    }

    public function store(Order $order): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::OrdersManage);
        $this->assertInReadScope($order);

        $invoice = $this->invoices->generate($order, $this->organization());

        // 201 for a freshly generated invoice, 200 when it already existed (idempotent).
        return successResponse($invoice, __('api.created_success'), $invoice->wasRecentlyCreated ? 201 : 200);
    }

    public function show(Order $order): JsonResponse
    {
        // Reading the stored invoice is open to any staff of the tenant (no orders.manage).
        $this->staff();
        $this->assertInReadScope($order);

        $invoice = ZatcaInvoice::query()
            ->forOrganization($this->organizationId())
            ->where('order_id', $order->getKey())
            ->first();

        abort_if($invoice === null, 404, __('api.record_not_found'));

        return successResponse($invoice);
    }
}

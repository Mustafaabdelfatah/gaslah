<?php

namespace App\Http\Controllers\API\Tenancy\Zatca;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Order;
use App\Services\Zatca\ZatcaInvoiceService;
use Illuminate\Http\JsonResponse;

/**
 * ZATCA Phase 1 — the instant, unstored tax invoice with its QR, built on every request.
 */
class ZatcaController extends TenantController
{
    public function __construct(private readonly ZatcaInvoiceService $invoices)
    {
        parent::__construct();
    }

    public function invoice(Order $order): JsonResponse
    {
        $this->staff();
        $this->assertOwned($order);

        return successResponse($this->invoices->phaseOneInvoice($order, $this->organization()));
    }

    private function assertOwned(Order $order): void
    {
        abort_unless(in_array($order->branch_id, $this->readBranchIds(), true), 404, __('api.record_not_found'));
    }
}

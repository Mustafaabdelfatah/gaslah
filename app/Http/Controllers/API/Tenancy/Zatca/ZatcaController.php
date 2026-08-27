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
        $this->assertInReadScope($order);

        return successResponse($this->invoices->phaseOneInvoice($order, $this->organization()));
    }
}

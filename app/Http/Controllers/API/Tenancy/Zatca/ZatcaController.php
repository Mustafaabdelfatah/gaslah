<?php

namespace App\Http\Controllers\API\Tenancy\Zatca;

use App\Enum\Zatca\ZatcaInvoiceStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Order;
use App\Models\ZatcaInvoice;
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

    /**
     * Where this organization stands on e-invoicing, stated plainly.
     *
     * The screen this feeds decides whether a laundry believes it is compliant, so it
     * reports only what is true: phase 1 needs nothing but a VAT number, phase 2 is
     * generating and chaining locally, and the link to the authority — onboarding,
     * signing and reporting — is not built. Saying otherwise would be worse than
     * saying nothing.
     */
    public function status(): JsonResponse
    {
        $organization = $this->organization();

        $chain = ZatcaInvoice::query()->forOrganization($organization->getKey());
        $last = (clone $chain)->orderByDesc('icv')->first();

        return successResponse([
            'seller' => [
                'name' => $organization->name,
                'vat_number' => $organization->vat_number,
                'cr_number' => $organization->cr_number,
                'address' => $organization->address,
            ],

            // Phase 1 is built per request and stored nowhere, so it works the moment
            // the organization has a VAT number to put in the QR.
            'phase_one_ready' => filled($organization->vat_number),

            'chain' => [
                'count' => (clone $chain)->count(),
                'last_icv' => $last?->icv,
                'last_invoice_at' => $last?->created_at,
                'reported_count' => (clone $chain)->where('status', ZatcaInvoiceStatusEnum::Reported->value)->count(),
            ],

            // Onboarding needs a CSID from the authority, which is OTP-gated behind
            // their portal. Until that exists this stays false, and the screen says so.
            'onboarded' => false,
            'gaps' => [
                'onboarding' => __('api.zatca_gap_onboarding'),
                'signing' => __('api.zatca_gap_signing'),
                'reporting' => __('api.zatca_gap_reporting'),
            ],
        ]);
    }
}

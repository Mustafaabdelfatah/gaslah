<?php

namespace App\Http\Controllers\API\Tenancy\Zatca;

use App\Enum\Zatca\ZatcaInvoiceStatusEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Order;
use App\Models\ZatcaInvoice;
use App\Services\Zatca\ZatcaInvoiceService;
use App\Services\Zatca\ZatcaOnboardingService;
use Illuminate\Http\JsonResponse;

/**
 * ZATCA Phase 1 — the instant, unstored tax invoice with its QR, built on every request.
 */
class ZatcaController extends TenantController
{
    public function __construct(
        private readonly ZatcaInvoiceService $invoices,
        private readonly ZatcaOnboardingService $onboarding,
    ) {
        parent::__construct();
    }

    public function invoice(Order $order): JsonResponse
    {
        $this->staff();
        $this->assertInReadScope($order);

        return successResponse($this->invoices->phaseOneInvoice($order, $this->organization()));
    }

    public function status(): JsonResponse
    {
        $this->requireManager();
        $organization = $this->organization();
        $onboarding = $this->onboarding->status($organization);

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

            ...$onboarding,
            'gaps' => array_filter([
                'onboarding' => $onboarding['has_compliance_csid'] ? null : __('api.zatca_gap_onboarding'),
                'signing' => __('api.zatca_gap_signing'),
                'reporting' => __('api.zatca_gap_reporting'),
            ]),
        ]);
    }
}

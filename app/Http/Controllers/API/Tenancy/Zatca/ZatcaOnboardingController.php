<?php

namespace App\Http\Controllers\API\Tenancy\Zatca;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Zatca\GenerateZatcaCsrRequest;
use App\Http\Requests\Zatca\SubmitZatcaComplianceRequest;
use App\Services\Zatca\ZatcaOnboardingService;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Staff-managed ZATCA onboarding. Secrets never leave the service boundary.
 */
class ZatcaOnboardingController extends TenantController
{
    public function __construct(private readonly ZatcaOnboardingService $onboarding)
    {
        parent::__construct();
    }

    public function csr(GenerateZatcaCsrRequest $request): JsonResponse
    {
        $this->requireManager();

        try {
            $result = $this->onboarding->generateCsr($this->organization(), $request->force());
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (RuntimeException) {
            return failResponse(__('api.zatca_csr_generation_failed'), code: 503);
        }

        return successResponse($result, __('api.zatca_csr_created'));
    }

    public function compliance(SubmitZatcaComplianceRequest $request): JsonResponse
    {
        $this->requireManager();
        $result = $this->onboarding->comply($this->organization(), $request->otp());

        if (! $result['ok']) {
            return failResponse(
                __('api.zatca_compliance_failed'),
                ['gateway_status' => $result['gateway_status']],
                422,
            );
        }

        return successResponse($result, __('api.zatca_compliance_completed'));
    }
}

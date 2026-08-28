<?php

namespace App\Http\Controllers\API\Tenancy\Settings;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Settings\UpdateIntegrationsRequest;
use App\Services\Settings\IntegrationSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * A tenant's third-party credentials: payment gateway, WhatsApp, SMS.
 *
 * Reading is open to staff — the screen has to show which integrations are live — while
 * changing them is the owner's, since a gateway key decides where the laundry's money
 * goes. Neither path can expose a stored secret: the service blanks them on the way out.
 */
class IntegrationSettingsController extends TenantController
{
    public function __construct(private readonly IntegrationSettingsService $integrations)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        $this->staff();

        return successResponse($this->integrations->present($this->organizationId()));
    }

    public function update(UpdateIntegrationsRequest $request): JsonResponse
    {
        return successResponse(
            $this->integrations->update($this->organizationId(), $request->validated()),
            __('api.updated_success'),
        );
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Settings;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Settings\UpdatePortalSettingsRequest;
use App\Http\Resources\Settings\PortalSettingsResource;
use App\Services\Tenancy\TenantSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * The customer portal's identity: the slug it is reached at, its branding, and the
 * documents it links to.
 */
class PortalSettingsController extends TenantController
{
    public function __construct(private readonly TenantSettingsService $settings)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        return successResponse(new PortalSettingsResource($this->organization()));
    }

    public function update(UpdatePortalSettingsRequest $request): JsonResponse
    {
        $organization = $this->settings->updatePortal(
            $this->organization(),
            $request->logoUrl(),
            $request->slug(),
            $request->customDomain(),
            $request->portalConfig(),
        );

        return successResponse(new PortalSettingsResource($organization), __('api.updated_success'));
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Settings;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Settings\UpdateBusinessSettingsRequest;
use App\Http\Resources\Settings\BusinessSettingsResource;
use App\Services\Tenancy\TenantSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * The organization's own commercial profile: invoice details, tax rate and brand.
 *
 * Managers may read it; only the general manager may change it, since it decides what
 * every invoice the business issues says about it.
 */
class BusinessSettingsController extends TenantController
{
    public function __construct(private readonly TenantSettingsService $settings)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        return successResponse(new BusinessSettingsResource($this->organization()));
    }

    public function update(UpdateBusinessSettingsRequest $request): JsonResponse
    {
        $organization = $this->settings->updateBusiness($this->organization(), $request->profile());

        return successResponse(new BusinessSettingsResource($organization), __('api.updated_success'));
    }
}

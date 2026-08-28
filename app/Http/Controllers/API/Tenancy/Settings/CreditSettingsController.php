<?php

namespace App\Http\Controllers\API\Tenancy\Settings;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Settings\UpdateCreditSettingsRequest;
use App\Http\Resources\Settings\CreditSettingsResource;
use App\Services\Tenancy\TenantSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Whether the organization sells on account, and the limit a new customer starts with.
 */
class CreditSettingsController extends TenantController
{
    public function __construct(private readonly TenantSettingsService $settings)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        return successResponse(new CreditSettingsResource($this->settings->credit($this->organization())));
    }

    public function update(UpdateCreditSettingsRequest $request): JsonResponse
    {
        $setting = $this->settings->updateCredit($this->organization(), $request->settings());

        return successResponse(new CreditSettingsResource($setting), __('api.updated_success'));
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Orders\AutomationSettingsRequest;
use App\Models\AutomationSetting;
use Illuminate\Http\JsonResponse;

/**
 * Order auto-advance automation settings for the organization.
 */
class AutomationController extends TenantController
{
    public function show(): JsonResponse
    {
        $this->requireManager();

        return successResponse($this->settings());
    }

    public function update(AutomationSettingsRequest $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $setting = AutomationSetting::query()->updateOrCreate(
            ['organization_id' => $this->organizationId()],
            ['enabled' => $request->isEnabled(), 'delays' => $request->delays()],
        );

        return successResponse($setting, __('api.updated_success'));
    }

    private function settings(): AutomationSetting
    {
        return AutomationSetting::query()->firstOrNew(['organization_id' => $this->organizationId()]);
    }
}

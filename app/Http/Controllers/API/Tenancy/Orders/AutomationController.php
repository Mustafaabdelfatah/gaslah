<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\AutomationSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    public function update(Request $request): JsonResponse
    {
        $this->requireSuperAdmin();

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'delays' => ['nullable', 'array'],
            'delays.default.normal' => ['nullable', 'integer', 'min:1'],
            'delays.default.express' => ['nullable', 'integer', 'min:1'],
        ]);

        $setting = AutomationSetting::query()->updateOrCreate(
            ['organization_id' => $this->organizationId()],
            ['enabled' => $data['enabled'], 'delays' => $data['delays'] ?? null],
        );

        return successResponse($setting, __('api.updated_success'));
    }

    private function settings(): AutomationSetting
    {
        return AutomationSetting::query()->firstOrNew(['organization_id' => $this->organizationId()]);
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Orders;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Orders\AutomationSettingsRequest;
use App\Models\AutomationSetting;
use App\Services\Orders\AutomationSweeper;
use Illuminate\Http\JsonResponse;

/**
 * Order auto-advance automation settings for the organization.
 */
class AutomationController extends TenantController
{
    public function show(): JsonResponse
    {
        $setting = $this->settings();

        return successResponse([
            'enabled' => (bool) $setting->enabled,
            // An unsaved row carries no schema defaults, so the delays the sweeper
            // would actually use are reported rather than a pair of nulls the screen
            // would render as empty boxes.
            'delays' => [
                'default' => [
                    'normal' => (int) ($setting->delays['default']['normal'] ?? AutomationSweeper::DEFAULT_DELAYS['normal']),
                    'express' => (int) ($setting->delays['default']['express'] ?? AutomationSweeper::DEFAULT_DELAYS['express']),
                ],
            ],
        ]);
    }

    public function update(AutomationSettingsRequest $request): JsonResponse
    {

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

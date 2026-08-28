<?php

namespace App\Http\Controllers\API\Tenancy\Settings;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Settings\UpdateNotificationSettingsRequest;
use App\Http\Resources\Settings\NotificationSettingsResource;
use App\Services\Tenancy\TenantSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Which dashboard alerts the organization wants raised.
 */
class NotificationSettingsController extends TenantController
{
    public function __construct(private readonly TenantSettingsService $settings)
    {
        parent::__construct();
    }

    public function show(): JsonResponse
    {
        return successResponse(new NotificationSettingsResource($this->settings->notifications($this->organization())));
    }

    public function update(UpdateNotificationSettingsRequest $request): JsonResponse
    {
        $setting = $this->settings->updateNotifications($this->organization(), $request->switches());

        return successResponse(new NotificationSettingsResource($setting), __('api.updated_success'));
    }
}

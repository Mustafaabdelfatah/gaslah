<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Platform\UpdatePlatformSettingsRequest;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * The operator's settings centre.
 *
 * The owner's alone — holding `manage_config` is not enough. These decide who the platform
 * is on the invoices it issues and how much of itself it may sell, which is not a
 * delegable call. The rule is enforced on the routes.
 */
class AdminConfigController extends BaseController
{
    public function __construct(private readonly PlatformSettingsService $settings)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        return successResponse([
            'groups' => PlatformSettingsService::groups(),
            'settings' => $this->settings->all(),
        ]);
    }

    public function show(string $group): JsonResponse
    {
        $this->assertGroup($group);

        return successResponse($this->settings->group($group));
    }

    public function update(UpdatePlatformSettingsRequest $request, string $group): JsonResponse
    {
        $this->assertGroup($group);

        return successResponse($this->settings->save($group, $request->values()), __('api.updated_success'));
    }

    /**
     * A group that is not one of ours does not exist. That includes the rows the platform
     * writes for itself, which are reachable in the store but never through here.
     */
    private function assertGroup(string $group): void
    {
        abort_unless(PlatformSettingsService::isGroup($group), 404, __('api.record_not_found'));
    }
}

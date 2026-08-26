<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformAuditActionEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\DunningLog;
use App\Services\Platform\DunningService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The dunning console (manage_subscriptions): view and edit the policy, and run a cycle
 * on demand. Every write is audited.
 */
class AdminDunningController extends PlatformBaseController
{
    public function __construct(
        private readonly DunningService $dunning,
        private readonly PlatformAuditService $audit,
    ) {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        return successResponse([
            'policy' => $this->dunning->policy(),
            'activity' => DunningLog::query()
                ->with('organization:id,name')
                ->latest('id')
                ->limit(50)
                ->get(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'remind_days_before' => ['nullable', 'array'],
            'remind_days_before.*' => ['integer', 'min:1', 'max:365'],
            'remind_days_after' => ['nullable', 'array'],
            'remind_days_after.*' => ['integer', 'min:1', 'max:365'],
            'grace_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'channels' => ['nullable', 'array'],
            'channels.whatsapp' => ['nullable', 'boolean'],
            'channels.email' => ['nullable', 'boolean'],
        ]);

        $policy = $this->dunning->savePolicy($data);

        $this->audit->log($this->admin(), PlatformAuditActionEnum::Dunning, null, ['action' => 'update_policy']);

        return successResponse($policy, __('api.updated_success'));
    }

    public function run(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageSubscriptions);

        $summary = $this->dunning->run();

        $this->audit->log($this->admin(), PlatformAuditActionEnum::Dunning, null, ['action' => 'run', ...$summary]);

        return successResponse($summary);
    }
}

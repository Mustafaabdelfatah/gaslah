<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Platform\PlatformAuditActionEnum;
use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\OrganizationScopeFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\DunningPolicyRequest;
use App\Http\Resources\Platform\DunningLogResource;
use App\Models\DunningLog;
use App\Models\User;
use App\Services\Platform\DunningService;
use App\Services\Platform\PlatformAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;

/**
 * The dunning console: read and edit the policy, run a cycle on demand, and read the
 * trail of what the cycle did. Gated on manage_subscriptions at the routes; every write
 * is audited.
 */
class AdminDunningController extends BaseController
{
    public function __construct(
        private readonly DunningService $dunning,
        private readonly PlatformAuditService $audit,
    ) {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        return successResponse(['policy' => $this->dunning->policy()]);
    }

    /**
     * The dunning trail, newest first — optionally scoped to one tenant.
     */
    public function activity(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(DunningLog::query()->with('organization:id,name'))
            ->through([OrganizationScopeFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, DunningLogResource::class));
    }

    public function update(DunningPolicyRequest $request): JsonResponse
    {
        $policy = $this->dunning->savePolicy($request->validated());

        $this->audit->log($this->admin(), PlatformAuditActionEnum::Dunning, null, ['action' => 'update_policy']);

        return successResponse($policy, __('api.updated_success'));
    }

    public function run(): JsonResponse
    {
        $summary = $this->dunning->run();

        $this->audit->log($this->admin(), PlatformAuditActionEnum::Dunning, null, ['action' => 'run', ...$summary]);

        return successResponse($summary);
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}

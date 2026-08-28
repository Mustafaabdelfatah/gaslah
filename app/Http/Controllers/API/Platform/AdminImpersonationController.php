<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Models\Organization;
use App\Models\User;
use App\Services\Platform\ImpersonationService;
use Illuminate\Http\JsonResponse;

/**
 * Entering a tenant as one of its staff, for support.
 *
 * Owner-only, enforced on the route: no combination of granted permissions should add up
 * to "may act as somebody else". Every start is audited.
 */
class AdminImpersonationController extends BaseController
{
    public function __construct(private readonly ImpersonationService $impersonation)
    {
        parent::__construct();
    }

    public function start(Organization $organization): JsonResponse
    {
        $session = $this->impersonation->start($organization, $this->admin());

        return successResponse([
            'token' => $session['token'],
            'expires_at' => $session['expires_at'],
            'organization' => $organization->only('id', 'name', 'slug'),
            'acting_as' => $session['user']->only('id', 'name', 'email'),
        ]);
    }

    public function stop(): JsonResponse
    {
        return successResponse(
            ['ended' => $this->impersonation->stop($this->admin())],
            __('api.impersonation_stopped'),
        );
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}

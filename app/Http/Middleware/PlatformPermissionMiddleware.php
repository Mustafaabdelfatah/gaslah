<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Tenancy\PlatformAccessService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a platform-console route on the operator's platform permissions.
 *
 * The authenticated principal must be a platform-admin User (a tenant staff token is
 * refused), and permissions are re-checked against the live database on every request, so
 * a disabled or downgraded admin loses access at once. Passing several permissions grants
 * access when the admin holds ANY of them:
 *
 *     ->middleware('platform.permission:manage_plans')
 *     ->middleware('platform.permission:manage_plans,view_finance')
 */
class PlatformPermissionMiddleware
{
    public function __construct(private readonly PlatformAccessService $platform) {}

    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;

        $this->platform->requirePlatformAdmin($user);

        if ($permissions === []) {
            return $next($request);
        }

        foreach ($permissions as $permission) {
            if ($this->platform->has($user, $permission)) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN, __('api.unauthorized'));
    }
}

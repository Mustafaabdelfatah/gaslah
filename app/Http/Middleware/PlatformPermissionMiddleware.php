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
 *
 * The reserved word `owner` requires the owner role itself rather than a permission. Some
 * acts — acting as somebody else, rewriting the platform's own configuration — should not
 * be reachable by any combination of granted permissions.
 *
 *     ->middleware('platform.permission:owner')
 */
class PlatformPermissionMiddleware
{
    /**
     * Not a permission: the owner role itself.
     */
    private const OWNER = 'owner';

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
            $granted = $permission === self::OWNER
                ? $this->platform->isOwner($user)
                : $this->platform->has($user, $permission);

            if ($granted) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN, __('api.unauthorized'));
    }
}

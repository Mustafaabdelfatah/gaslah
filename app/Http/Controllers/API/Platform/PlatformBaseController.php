<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Http\Controllers\API\BaseController;
use App\Models\User;
use App\Services\Tenancy\PlatformAccessService;

/**
 * Base for the platform (Gaslah operator) console. The authenticated principal is a
 * platform-admin User; a tenant staff token is refused. Permissions are re-checked
 * against the live database on every request, so a disabled or downgraded admin loses
 * access at once.
 */
abstract class PlatformBaseController extends BaseController
{
    protected PlatformAccessService $platform;

    public function __construct()
    {
        parent::__construct();

        $this->platform = app(PlatformAccessService::class);
    }

    /**
     * The authenticated platform admin.
     */
    protected function admin(): User
    {
        $user = request()->user();
        $this->platform->requirePlatformAdmin($user instanceof User ? $user : null);

        /** @var User $user */
        return $user;
    }

    protected function requirePlatformPermission(PlatformPermissionEnum|string $permission): void
    {
        $this->platform->requirePermission($this->admin(), $permission);
    }

    /**
     * Require any one of the given platform permissions.
     *
     * @param  array<int, PlatformPermissionEnum|string>  $permissions
     */
    protected function requireAnyPlatformPermission(array $permissions): void
    {
        $admin = $this->admin();

        foreach ($permissions as $permission) {
            if ($this->platform->has($admin, $permission)) {
                return;
            }
        }

        abort(403, __('api.unauthorized'));
    }
}

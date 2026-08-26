<?php

namespace App\Services\Platform;

use App\Enum\Platform\PlatformAuditActionEnum;
use App\Models\Organization;
use App\Models\PlatformAuditLog;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Writes the append-only trail of platform-admin actions against tenants. Read back
 * through the platform activity console.
 */
class PlatformAuditService
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function log(User $admin, PlatformAuditActionEnum $action, ?Organization $organization = null, array $meta = []): PlatformAuditLog
    {
        return PlatformAuditLog::query()->create([
            'admin_id' => $admin->getKey(),
            'organization_id' => $organization?->getKey(),
            'action' => $action,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => Carbon::now(),
        ]);
    }
}

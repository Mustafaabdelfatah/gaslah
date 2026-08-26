<?php

namespace App\Http\Controllers\API\Tenancy\Platform;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\PlatformAnnouncement;
use Illuminate\Http\JsonResponse;

/**
 * The tenant's read of the platform broadcast banners aimed at it — active, in-window,
 * and targeting either every tenant or this organization.
 */
class OrgNoticeController extends TenantController
{
    public function index(): JsonResponse
    {
        $limit = (int) config('services.platform.tenant_notice_limit', 10);

        $notices = PlatformAnnouncement::query()
            ->visibleTo($this->organizationId())
            ->latest()
            ->limit($limit)
            ->get(['id', 'title', 'body', 'level', 'starts_at', 'ends_at']);

        return successResponse($notices);
    }
}

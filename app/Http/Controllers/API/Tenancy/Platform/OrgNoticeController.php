<?php

namespace App\Http\Controllers\API\Tenancy\Platform;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Resources\Platform\TenantNoticeResource;
use App\Models\PlatformAnnouncement;
use App\Services\Platform\PlatformSettingsService;
use Illuminate\Http\JsonResponse;

/**
 * The tenant's read of the platform banners aimed at it — active, in-window, and
 * targeting either every tenant or this organization.
 */
class OrgNoticeController extends TenantController
{
    public function __construct(private readonly PlatformSettingsService $settings)
    {
        parent::__construct();
    }

    /**
     * Unpaginated by design: this is a dashboard banner strip, capped at a handful the
     * operator sets, not a list anyone scrolls.
     */
    public function index(): JsonResponse
    {
        $notices = PlatformAnnouncement::query()
            ->visibleTo($this->organizationId())
            ->latest()
            ->limit($this->settings->announcements()['tenantNoticeLimit'])
            ->get();

        return successResponse(TenantNoticeResource::collection($notices));
    }
}

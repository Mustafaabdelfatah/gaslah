<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Services\Platform\PlatformStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Cross-tenant KPIs and recurring-revenue analytics for the platform operator.
 */
class PlatformStatsController extends PlatformBaseController
{
    public function __construct(private readonly PlatformStatsService $stats)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ViewFinance);

        return successResponse($this->stats->build());
    }
}

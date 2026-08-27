<?php

namespace App\Http\Controllers\API\Platform;

use App\Http\Controllers\API\BaseController;
use App\Services\Platform\PlatformStatsService;
use Illuminate\Http\JsonResponse;

/**
 * Cross-tenant KPIs and recurring-revenue analytics for the platform operator. Gated on
 * view_finance at the route.
 */
class PlatformStatsController extends BaseController
{
    public function __construct(private readonly PlatformStatsService $stats)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        return successResponse($this->stats->build());
    }
}

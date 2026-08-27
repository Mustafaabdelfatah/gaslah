<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Reports\DateRangeRequest;
use App\Services\Reports\AnalyticsService;
use App\Services\Reports\ReportRangeService;
use Illuminate\Http\JsonResponse;

class AnalyticsController extends TenantController
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ReportRangeService $ranges,
    ) {
        parent::__construct();
    }

    public function index(DateRangeRequest $request): JsonResponse
    {
        $this->staff();

        $range = $this->ranges->resolve($request->from(), $request->to());

        return successResponse($this->analytics->build($this->readBranchIds(), $range));
    }
}

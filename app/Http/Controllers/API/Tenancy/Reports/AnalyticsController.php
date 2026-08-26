<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Reports\AnalyticsService;
use App\Services\Reports\ReportRangeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnalyticsController extends TenantController
{
    public function __construct(
        private readonly AnalyticsService $analytics,
        private readonly ReportRangeService $ranges,
    ) {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $this->staff();
        $this->requireFeature('analytics');

        $request->validate(['from' => ['nullable', 'date'], 'to' => ['nullable', 'date']]);
        $range = $this->ranges->resolve($request->input('from'), $request->input('to'));

        return successResponse($this->analytics->build($this->readBranchIds(), $range));
    }
}

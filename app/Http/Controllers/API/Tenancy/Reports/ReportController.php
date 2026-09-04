<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Reports\DateRangeRequest;
use App\Http\Requests\Reports\TopCustomersRequest;
use App\Services\Reports\ReportRangeService;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;

class ReportController extends TenantController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportRangeService $ranges,
    ) {
        parent::__construct();
    }

    public function overview(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->reports->overview($this->readBranchIds(), $this->range($request)));
    }

    public function managementOverview(TopCustomersRequest $request): JsonResponse
    {
        return successResponse(
            $this->reports->managementOverview(
                $this->readBranchIds(),
                $this->range($request),
                $request->limit(),
            ),
        );
    }

    public function sales(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->reports->sales($this->readBranchIds(), $this->range($request)));
    }

    public function topProducts(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->reports->topProducts($this->readBranchIds(), $this->range($request)));
    }

    public function topCustomers(TopCustomersRequest $request): JsonResponse
    {
        return successResponse(
            $this->reports->topCustomers($this->readBranchIds(), $this->range($request), $request->limit()),
        );
    }

    public function cancellationRate(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->reports->cancellationRate($this->readBranchIds(), $this->range($request)));
    }

    /**
     * The resolved reporting window — the range service supplies the tenant's defaults and
     * clamps anything unreasonable.
     *
     * @return array<string, mixed>
     */
    private function range(DateRangeRequest $request): array
    {
        return $this->ranges->resolve($request->from(), $request->to());
    }
}

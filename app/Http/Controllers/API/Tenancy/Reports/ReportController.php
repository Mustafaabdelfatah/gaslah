<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Reports\ReportRangeService;
use App\Services\Reports\ReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends TenantController
{
    public function __construct(
        private readonly ReportService $reports,
        private readonly ReportRangeService $ranges,
    ) {
        parent::__construct();
    }

    public function sales(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ReportsView);

        return successResponse($this->reports->sales($this->readBranchIds(), $this->range($request)));
    }

    public function topProducts(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ReportsView);

        return successResponse($this->reports->topProducts($this->readBranchIds(), $this->range($request)));
    }

    public function topCustomers(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $this->validateRange($request, ['limit' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $limit = (int) ($data['limit'] ?? 10);

        return successResponse($this->reports->topCustomers($this->readBranchIds(), $this->range($request), $limit));
    }

    public function cancellationRate(Request $request): JsonResponse
    {
        $this->requireManager();

        return successResponse($this->reports->cancellationRate($this->readBranchIds(), $this->range($request)));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function range(Request $request): array
    {
        $this->validateRange($request);

        return $this->ranges->resolve($request->input('from'), $request->input('to'));
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function validateRange(Request $request, array $extra = []): array
    {
        return $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            ...$extra,
        ]);
    }
}

<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Reports\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends TenantController
{
    public function __construct(private readonly DashboardService $dashboard)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $this->staff();

        $branchIds = $this->readBranchIds();

        // An explicit branch is always re-validated against the caller's branches, so it
        // narrows the scope but never widens it.
        if ($request->filled('branch')) {
            $branch = (int) $request->input('branch');
            abort_unless(in_array($branch, $branchIds, true), 403, __('api.branch_not_available'));
            $branchIds = [$branch];
        }

        return successResponse($this->dashboard->build($branchIds, $this->organizationId()));
    }
}

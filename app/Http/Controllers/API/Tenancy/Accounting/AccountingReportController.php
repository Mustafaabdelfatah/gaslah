<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\ReportFilterRequest;
use App\Models\Account;
use App\Services\Accounting\AccountingReportService;
use Illuminate\Http\JsonResponse;

/**
 * Read-only financial reports for the caller's organization.
 */
class AccountingReportController extends TenantController
{
    public function __construct(private readonly AccountingReportService $reports)
    {
        parent::__construct();
    }

    public function trialBalance(ReportFilterRequest $request): JsonResponse
    {

        return successResponse($this->reports->trialBalance($this->organizationId(), $request->toFilter()));
    }

    public function incomeStatement(ReportFilterRequest $request): JsonResponse
    {

        return successResponse($this->reports->incomeStatement($this->organizationId(), $request->toFilter()));
    }

    public function balanceSheet(ReportFilterRequest $request): JsonResponse
    {

        return successResponse($this->reports->balanceSheet(
            $this->organizationId(),
            $request->input('as_of'),
            $request->input('branch_id') ? (int) $request->input('branch_id') : null,
        ));
    }

    public function vatReturn(ReportFilterRequest $request): JsonResponse
    {

        return successResponse($this->reports->vatReturn($this->organizationId(), $request->toFilter()));
    }

    public function ledger(ReportFilterRequest $request, Account $account): JsonResponse
    {
        abort_unless($account->organization_id === $this->organizationId(), 404, __('api.record_not_found'));

        return successResponse($this->reports->ledger($account, $request->input('from'), $request->input('to')));
    }
}

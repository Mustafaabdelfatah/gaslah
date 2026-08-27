<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Reports\BankReconciliationRequest;
use App\Http\Requests\Reports\ClearAllBankLinesRequest;
use App\Http\Requests\Reports\ClearBankLineRequest;
use App\Http\Requests\Reports\StatementBalanceRequest;
use App\Services\Reports\BankService;
use Illuminate\Http\JsonResponse;

/**
 * Organization-level bank reconciliation — manager gated.
 */
class BankController extends TenantController
{
    public function __construct(private readonly BankService $bank)
    {
        parent::__construct();
    }

    public function reconciliation(BankReconciliationRequest $request): JsonResponse
    {
        $this->requireManager();

        return successResponse($this->bank->reconciliation($this->organizationId(), $request->limit()));
    }

    public function clear(ClearBankLineRequest $request): JsonResponse
    {
        $this->requireManager();

        return successResponse(
            $this->bank->toggleClear($this->organizationId(), $request->lineId(), $request->isCleared()),
        );
    }

    public function clearAll(ClearAllBankLinesRequest $request): JsonResponse
    {
        $this->requireManager();

        return successResponse($this->bank->clearAll($this->organizationId(), $request->isCleared()));
    }

    public function statementBalance(StatementBalanceRequest $request): JsonResponse
    {
        $this->requireManager();

        return successResponse(
            $this->bank->setStatementBalance($this->organizationId(), $request->balance()),
            __('api.updated_success'),
        );
    }
}

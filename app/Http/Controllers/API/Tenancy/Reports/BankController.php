<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Reports\BankService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Organization-level bank reconciliation — manager gated.
 */
class BankController extends TenantController
{
    public function __construct(private readonly BankService $bank)
    {
        parent::__construct();
    }

    public function reconciliation(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validate(['limit' => ['nullable', 'integer', 'min:1', 'max:2000']]);

        return successResponse($this->bank->reconciliation($this->organizationId(), (int) ($data['limit'] ?? 500)));
    }

    public function clear(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validate([
            'line_id' => ['required', 'integer'],
            'cleared' => ['required', 'boolean'],
        ]);

        return successResponse($this->bank->toggleClear($this->organizationId(), (int) $data['line_id'], (bool) $data['cleared']));
    }

    public function clearAll(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validate(['cleared' => ['required', 'boolean']]);

        return successResponse($this->bank->clearAll($this->organizationId(), (bool) $data['cleared']));
    }

    public function statementBalance(Request $request): JsonResponse
    {
        $this->requireManager();

        $data = $request->validate(['balance' => ['required', 'numeric']]);

        return successResponse($this->bank->setStatementBalance($this->organizationId(), (float) $data['balance']), __('api.updated_success'));
    }
}

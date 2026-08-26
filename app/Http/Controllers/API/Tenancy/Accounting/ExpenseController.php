<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\StoreExpenseRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Expense;
use App\Services\Accounting\ExpenseService;
use Illuminate\Http\JsonResponse;

class ExpenseController extends TenantController
{
    public function __construct(private readonly ExpenseService $expenses)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $this->requireManager();

        $query = Expense::query()
            ->where('organization_id', $this->organizationId())
            ->when($request->filled('from'), fn ($q) => $q->whereDate('date', '>=', $request->input('from')))
            ->when($request->filled('to'), fn ($q) => $q->whereDate('date', '<=', $request->input('to')))
            ->latest('date');

        return successResponse(wrapPaginate($query));
    }

    public function store(StoreExpenseRequest $request): JsonResponse
    {
        $this->requireManager();

        $expense = $this->expenses->record([
            ...$request->validated(),
            'organization_id' => $this->organizationId(),
            'created_by_id' => $this->staff()->getKey(),
        ]);

        return successResponse($expense, __('api.created_success'), 201);
    }

    public function destroy(Expense $expense): JsonResponse
    {
        $this->requireManager();
        abort_unless($expense->organization_id === $this->organizationId(), 404, __('api.record_not_found'));

        $this->expenses->reverseAndDelete($expense, $this->staff()->getKey());

        return successResponse(msg: __('api.deleted_success'));
    }
}

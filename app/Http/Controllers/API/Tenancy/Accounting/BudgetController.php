<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\StoreBudgetRequest;
use App\Http\Requests\Accounting\UpdateBudgetRequest;
use App\Http\Requests\Global\Other\PageRequest;
use App\Models\Budget;
use App\Services\Accounting\BudgetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

/**
 * Planned spend per category and month, measured against posted expenses.
 */
class BudgetController extends TenantController
{
    public function __construct(private readonly BudgetService $budgets)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {

        $month = $request->filled('month')
            ? (string) $request->input('month')
            : Carbon::now()->format('Y-m');

        return successResponse($this->budgets->forMonth(
            $this->organizationId(),
            $month,
            $this->readBranchIds(),
            branchScoped: $request->hasHeader('X-Branch-Id'),
        ));
    }

    public function store(StoreBudgetRequest $request): JsonResponse
    {

        $budget = $this->budgets->upsert(
            $this->organizationId(),
            $request->validated(),
            $this->staff()->getKey(),
        );

        return successResponse($budget, __('api.created_success'), 201);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget): JsonResponse
    {
        $this->assertOwned($budget);

        $budget->update($request->validated());

        return successResponse($budget->refresh(), __('api.updated_success'));
    }

    public function destroy(Budget $budget): JsonResponse
    {
        $this->assertOwned($budget);

        $budget->delete();

        return successResponse(msg: __('api.deleted_success'));
    }
}

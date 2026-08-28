<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\ExpenseFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\PlatformExpenseRequest;
use App\Http\Resources\Platform\PlatformExpenseResource;
use App\Models\PlatformExpense;
use App\Models\User;
use App\Services\Platform\PlatformExpenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform's own operating costs. Recording one is an accounting act — it posts to the
 * books — so the whole surface sits behind manage_accounting.
 */
class AdminExpenseController extends BaseController
{
    public function __construct(private readonly PlatformExpenseService $expenses)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(PlatformExpense::query()->with('partner:id,name')->latest('date'))
            ->through([ExpenseFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PlatformExpenseResource::class, [
            'outstanding_total' => round(array_sum($this->expenses->outstandingByPartner()), 2),
        ]));
    }

    public function store(PlatformExpenseRequest $request): JsonResponse
    {
        /** @var User $admin */
        $admin = request()->user();

        $expense = $this->expenses->record($request->validated(), $admin->getKey());

        return successResponse(
            new PlatformExpenseResource($expense->load('partner:id,name')),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }

    /**
     * Settle up with a partner who fronted a cost.
     */
    public function reimburse(PlatformExpense $expense): JsonResponse
    {
        /** @var User $admin */
        $admin = request()->user();

        $expense = $this->expenses->reimburse($expense, $admin->getKey());

        return successResponse(
            new PlatformExpenseResource($expense->load('partner:id,name')),
            __('api.updated_success'),
        );
    }
}

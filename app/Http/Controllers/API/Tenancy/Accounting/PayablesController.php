<?php

namespace App\Http\Controllers\API\Tenancy\Accounting;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Accounting\SettlePayableRequest;
use App\Http\Requests\Accounting\StorePayableRequest;
use App\Http\Requests\Accounting\StoreRecurringBillRequest;
use App\Http\Resources\Accounting\PayableResource;
use App\Http\Resources\Accounting\RecurringBillResource;
use App\Models\Payable;
use App\Models\RecurringBill;
use App\Services\Accounting\PayablesService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Staff finance workflow for supplier bills and recurring expense templates.
 */
class PayablesController extends TenantController
{
    public function __construct(private readonly PayablesService $payables)
    {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $result = $this->payables->overview($this->organizationId(), $this->readBranchIds());

        return successResponse([
            'data' => PayableResource::collection($result['bills'])->resolve($request),
            'summary' => $result['summary'],
        ]);
    }

    public function store(StorePayableRequest $request): JsonResponse
    {
        $payable = $this->payables->createBill(
            $this->organizationId(),
            $this->writeBranchId(),
            $this->staff()->getKey(),
            $request->validated(),
        );

        return successResponse(new PayableResource($payable), __('api.created_success'), 201);
    }

    public function settle(SettlePayableRequest $request, Payable $payable): JsonResponse
    {
        $this->assertOwned($payable);
        $this->assertPayableInReadScope($payable);

        $settled = $this->payables->settle(
            $payable,
            $request->validated('via'),
            $request->validated('date'),
            $this->staff()->getKey(),
        );

        return successResponse(new PayableResource($settled), __('api.updated_success'));
    }

    public function destroy(Payable $payable): JsonResponse
    {
        $this->assertOwned($payable);
        $this->assertPayableInReadScope($payable);

        $this->payables->void($payable, $this->staff()->getKey());

        return successResponse(msg: __('api.deleted_success'));
    }

    public function suppliers(): JsonResponse
    {
        return successResponse($this->payables->suppliers(
            $this->organizationId(),
            $this->readBranchIds(),
        ));
    }

    public function recurringIndex(Request $request): JsonResponse
    {
        $templates = $this->payables->recurring($this->organizationId(), $this->readBranchIds());

        return successResponse(RecurringBillResource::collection($templates)->resolve($request));
    }

    public function recurringStore(StoreRecurringBillRequest $request): JsonResponse
    {
        $data = $request->validated();
        $this->assertBranchChoice($data['branch_id'] ?? null);

        $template = $this->payables->createRecurring(
            $this->organizationId(),
            $this->writeBranchId(),
            $data,
        );

        return successResponse(new RecurringBillResource($template), __('api.created_success'), 201);
    }

    public function recurringUpdate(StoreRecurringBillRequest $request, RecurringBill $recurringBill): JsonResponse
    {
        $this->assertOwned($recurringBill);
        $this->assertRecurringInReadScope($recurringBill);

        $data = $request->validated();
        $this->assertBranchChoice($data['branch_id'] ?? null);

        return successResponse(new RecurringBillResource(
            $this->payables->updateRecurring($recurringBill, $data),
        ), __('api.updated_success'));
    }

    public function recurringDestroy(RecurringBill $recurringBill): JsonResponse
    {
        $this->assertOwned($recurringBill);
        $this->assertRecurringInReadScope($recurringBill);
        $recurringBill->delete();

        return successResponse(msg: __('api.deleted_success'));
    }

    public function recurringRun(RecurringBill $recurringBill): JsonResponse
    {
        $this->assertOwned($recurringBill);
        $this->assertRecurringInReadScope($recurringBill);

        return successResponse([
            'generated' => $this->payables->materialize($recurringBill, $this->staff()->getKey()),
        ], __('api.created_success'));
    }

    private function assertPayableInReadScope(Payable $payable): void
    {
        $payable->loadMissing('expense');
        $branchId = $payable->expense?->branch_id;

        abort_if(
            $branchId !== null && ! in_array((int) $branchId, $this->readBranchIds(), true),
            404,
            __('api.record_not_found'),
        );
    }

    private function assertRecurringInReadScope(RecurringBill $recurringBill): void
    {
        abort_if(
            $recurringBill->branch_id !== null
                && ! in_array((int) $recurringBill->branch_id, $this->readBranchIds(), true),
            404,
            __('api.record_not_found'),
        );
    }

    private function assertBranchChoice(?int $branchId): void
    {
        abort_if(
            $branchId !== null && ! in_array($branchId, $this->readBranchIds(), true),
            404,
            __('api.record_not_found'),
        );
    }
}

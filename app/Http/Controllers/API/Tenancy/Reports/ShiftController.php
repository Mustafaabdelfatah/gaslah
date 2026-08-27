<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Filters\Global\OrderByFilter;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Reports\CloseShiftRequest;
use App\Http\Requests\Reports\OpenShiftRequest;
use App\Http\Resources\Reports\ShiftResource;
use App\Models\Shift;
use App\Services\Reports\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Symfony\Component\HttpFoundation\Response;

class ShiftController extends TenantController
{
    public function __construct(private readonly ShiftService $shifts)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {

        $query = app(Pipeline::class)
            ->send(Shift::query()->inBranches($this->readBranchIds())->with(['user:id,name', 'branch:id,name']))
            ->through([OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, ShiftResource::class));
    }

    public function current(): JsonResponse
    {

        $shift = $this->shifts->current($this->staff()->getKey());

        return successResponse([
            'open' => $shift !== null,
            'shift' => $shift === null ? null : $this->shifts->summarize($shift),
        ]);
    }

    public function open(OpenShiftRequest $request): JsonResponse
    {

        $summary = $this->shifts->open(
            $this->organizationId(),
            $this->writeBranchId(),
            $this->staff()->getKey(),
            $request->openingCash(),
        );

        return successResponse($summary, __('api.created_success'), Response::HTTP_CREATED);
    }

    public function close(CloseShiftRequest $request): JsonResponse
    {

        $shift = $this->shifts->current($this->staff()->getKey());
        abort_if($shift === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.shift_none_open'));

        return successResponse($this->shifts->close($shift, $request->actualCash()), __('api.updated_success'));
    }
}

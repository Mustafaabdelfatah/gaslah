<?php

namespace App\Http\Controllers\API\Tenancy\Reports;

use App\Enum\Tenancy\StaffPermissionEnum;
use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Shift;
use App\Services\Reports\ShiftService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ShiftController extends TenantController
{
    public function __construct(private readonly ShiftService $shifts)
    {
        parent::__construct();
    }

    public function current(): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ShiftsManage);

        $shift = $this->shifts->current($this->staff()->getKey());

        return successResponse([
            'open' => $shift !== null,
            'shift' => $shift !== null ? $this->shifts->summarize($shift) : null,
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ShiftsManage);

        $data = $request->validate(['opening_cash' => ['required', 'numeric', 'min:0']]);

        $summary = $this->shifts->open(
            $this->organizationId(),
            $this->writeBranchId(),
            $this->staff()->getKey(),
            (float) $data['opening_cash'],
        );

        return successResponse($summary, __('api.created_success'), 201);
    }

    public function close(Request $request): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ShiftsManage);

        $data = $request->validate([
            'actual_cash' => ['required', 'numeric', 'min:0'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $shift = $this->shifts->current($this->staff()->getKey());
        abort_if($shift === null, 422, __('api.shift_none_open'));

        return successResponse($this->shifts->close($shift, (float) $data['actual_cash']), __('api.updated_success'));
    }

    public function index(): JsonResponse
    {
        $this->requirePermission(StaffPermissionEnum::ShiftsManage);

        $shifts = Shift::query()
            ->inBranches($this->readBranchIds())
            ->with(['user:id,name', 'branch:id,name'])
            ->orderByDesc('opened_at')
            ->limit(50)
            ->get();

        $data = $shifts->map(fn (Shift $shift) => [
            'id' => $shift->getKey(),
            'status' => $shift->closed_at === null ? 'open' : 'closed',
            'cashier_name' => $shift->user?->name,
            'branch_name' => $shift->branch?->name,
            'opening_float' => round((float) $shift->opening_float, 2),
            'expected_cash' => $shift->closed_at !== null ? round((float) $shift->expected_cash, 2) : null,
            'actual_cash' => $shift->actual_cash !== null ? round((float) $shift->actual_cash, 2) : null,
            'variance' => $shift->variance !== null ? round((float) $shift->variance, 2) : null,
            'opened_at' => $shift->opened_at,
            'closed_at' => $shift->closed_at,
        ]);

        return successResponse($data);
    }
}

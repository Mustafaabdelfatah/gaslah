<?php

namespace App\Http\Controllers\API\Platform;

use App\Filters\Global\OrderByFilter;
use App\Filters\Platform\PayoutFilter;
use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Platform\MarkPayoutSentRequest;
use App\Http\Requests\Platform\PayoutDecisionRequest;
use App\Http\Requests\Platform\PayoutSettingsRequest;
use App\Http\Requests\Platform\StorePayoutBatchRequest;
use App\Http\Requests\Platform\UpdatePayoutFeeRequest;
use App\Http\Resources\Platform\PayoutSettlementResource;
use App\Models\PayoutSettlement;
use App\Models\User;
use App\Services\Payments\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Carbon;

/**
 * The platform operator's payout console: open batches, run the scheduled draw, and drive
 * the maker-checker approval flow.
 *
 * The routes carry the split — reads need view_finance or manage_payouts, every mutation
 * needs manage_payouts. Masking of the IBAN for a read-only viewer lives in the resource.
 */
class AdminPayoutController extends BaseController
{
    public function __construct(private readonly PayoutService $payouts)
    {
        parent::__construct();
    }

    public function index(PageRequest $request): JsonResponse
    {
        $query = app(Pipeline::class)
            ->send(
                PayoutSettlement::query()
                    ->with('organization:id,name')
                    ->withCount(['approvals as approve_count' => fn ($q) => $q->where('decision', 'approve')])
            )
            ->through([PayoutFilter::class, OrderByFilter::class])
            ->thenReturn();

        return successResponse(wrapPaginate($query, PayoutSettlementResource::class));
    }

    public function show(PayoutSettlement $settlement): JsonResponse
    {
        $settlement->load([
            'organization:id,name',
            'payments:id,order_id,amount,settlement_id,created_at',
            'approvals',
        ]);

        return successResponse(new PayoutSettlementResource($settlement));
    }

    public function store(StorePayoutBatchRequest $request): JsonResponse
    {
        $settlement = $this->payouts->createBatch(
            $request->organization(),
            $this->admin()->getKey(),
            $request->fee(),
            note: $request->note(),
        );

        return successResponse(new PayoutSettlementResource($settlement), __('api.created_success'), 201);
    }

    public function balances(): JsonResponse
    {
        return successResponse($this->payouts->balances());
    }

    /**
     * Run today's scheduled draw.
     */
    public function runDue(): JsonResponse
    {
        return successResponse($this->payouts->runDue(strtolower(Carbon::now()->format('D'))));
    }

    public function settings(): JsonResponse
    {
        return successResponse($this->payouts->settings());
    }

    public function updateSettings(PayoutSettingsRequest $request): JsonResponse
    {
        return successResponse($this->payouts->saveSettings($request->validated()), __('api.updated_success'));
    }

    public function approve(PayoutDecisionRequest $request, PayoutSettlement $settlement): JsonResponse
    {
        $settlement = $this->payouts->approve($settlement, $this->admin(), $request->note());

        return successResponse(new PayoutSettlementResource($settlement), __('api.updated_success'));
    }

    public function reject(PayoutDecisionRequest $request, PayoutSettlement $settlement): JsonResponse
    {
        $settlement = $this->payouts->reject($settlement, $this->admin(), $request->reason());

        return successResponse(new PayoutSettlementResource($settlement), __('api.updated_success'));
    }

    public function fee(UpdatePayoutFeeRequest $request, PayoutSettlement $settlement): JsonResponse
    {
        $settlement = $this->payouts->updateFee($settlement, $request->fee());

        return successResponse(new PayoutSettlementResource($settlement), __('api.updated_success'));
    }

    public function sent(MarkPayoutSentRequest $request, PayoutSettlement $settlement): JsonResponse
    {
        $settlement = $this->payouts->markSent($settlement, $this->admin(), $request->transferRef());

        return successResponse(new PayoutSettlementResource($settlement), __('api.updated_success'));
    }

    public function cancel(PayoutDecisionRequest $request, PayoutSettlement $settlement): JsonResponse
    {
        $settlement = $this->payouts->cancel($settlement, $request->reason());

        return successResponse(new PayoutSettlementResource($settlement), __('api.updated_success'));
    }

    private function admin(): User
    {
        /** @var User $user */
        $user = request()->user();

        return $user;
    }
}

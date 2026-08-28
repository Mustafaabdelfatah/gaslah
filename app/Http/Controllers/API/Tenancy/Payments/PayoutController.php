<?php

namespace App\Http\Controllers\API\Tenancy\Payments;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Global\Other\PageRequest;
use App\Http\Requests\Payments\PayoutConfigRequest;
use App\Http\Resources\Payments\PayoutSettlementSummaryResource;
use App\Models\PayoutSettlement;
use App\Services\Payments\PayoutService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The organization's view of its payouts: balance, schedule/bank configuration, and
 * urgent requests. Moving the organization's money is general-manager gated.
 */
class PayoutController extends TenantController
{
    public function __construct(private readonly PayoutService $payouts)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {

        return successResponse([
            'balance' => $this->payouts->unsettledSummary($this->organizationId()),
            'config' => $this->organization()->payout_config ?? [],
        ]);
    }

    /**
     * Past payout batches. Its own endpoint rather than a slice bolted onto the summary:
     * the history grows without limit, and a tenant looking back a year should be able to.
     */
    public function history(PageRequest $request): JsonResponse
    {
        $query = PayoutSettlement::query()
            ->where('organization_id', $this->organizationId())
            ->latest('id');

        return successResponse(wrapPaginate($query, PayoutSettlementSummaryResource::class));
    }

    /**
     * Set the payout schedule and receiving bank account.
     */
    public function config(PayoutConfigRequest $request): JsonResponse
    {

        $organization = $this->organization();
        $organization->update([
            'payout_config' => array_replace((array) $organization->payout_config, $request->validated()),
        ]);

        return successResponse($organization->refresh()->payout_config, __('api.updated_success'));
    }

    /**
     * Request an urgent settlement of the current balance.
     */
    public function request(): JsonResponse
    {

        $organization = $this->organization();
        $iban = $organization->payout_config['bank']['iban'] ?? null;
        abort_if(empty($iban), 422, __('api.payout_iban_required'));

        $userId = $this->staff()->getKey();
        $settlement = $this->payouts->createBatch($organization, $userId, null, urgent: true, requestedById: $userId);

        return successResponse($settlement, __('api.created_success'), 201);
    }
}

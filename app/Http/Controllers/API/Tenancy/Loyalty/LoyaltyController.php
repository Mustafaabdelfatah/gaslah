<?php

namespace App\Http\Controllers\API\Tenancy\Loyalty;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Http\Requests\Loyalty\AdjustPointsRequest;
use App\Http\Requests\Loyalty\LoyaltyProgramRequest;
use App\Http\Requests\Loyalty\RedeemPointsRequest;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;

class LoyaltyController extends TenantController
{
    public function __construct(private readonly LoyaltyService $loyalty)
    {
        parent::__construct();
    }

    /**
     * The organization's programme configuration (or the defaults template).
     */
    public function program(): JsonResponse
    {

        $program = $this->loyalty->resolveProgram($this->organizationId());

        return successResponse([
            'name' => $program->name,
            'earn_rate' => $program->earn_rate,
            'point_value' => $program->point_value,
            'expiry_months' => $program->expiry_months,
            'is_active' => $program->is_active,
            'exists' => $program->exists,
        ]);
    }

    public function updateProgram(LoyaltyProgramRequest $request): JsonResponse
    {

        $program = $this->loyalty->saveProgram($this->organizationId(), $request->validated());

        return successResponse($program, __('api.updated_success'));
    }

    /**
     * Loyalty balances of the caller's branches, richest first (up to 100).
     */
    public function accounts(): JsonResponse
    {

        return successResponse($this->loyalty->accounts($this->organizationId(), $this->readBranchIds()));
    }

    /**
     * Manually adjust a customer's points (positive or negative).
     */
    public function adjust(AdjustPointsRequest $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        $program = $this->requireSavedProgram();

        $account = $this->loyalty->adjust($customer, $program, $request->points(), $request->note());

        return successResponse([
            'points_balance' => $account->points_balance,
            'lifetime_points' => $account->lifetime_points,
        ], __('api.updated_success'));
    }

    /**
     * Redeem a customer's points for wallet value.
     */
    public function redeem(RedeemPointsRequest $request, Customer $customer): JsonResponse
    {
        $this->assertOwned($customer);

        $program = $this->requireSavedProgram();

        $result = $this->loyalty->redeem($customer, $program, $request->points());

        return successResponse($result, __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function requireSavedProgram(): LoyaltyProgram
    {
        $program = $this->loyalty->resolveProgram($this->organizationId());
        abort_unless($program->exists, 422, __('api.loyalty_program_not_active'));

        return $program;
    }
}

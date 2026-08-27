<?php

namespace App\Http\Controllers\API\Tenancy\Loyalty;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Models\Customer;
use App\Models\LoyaltyProgram;
use App\Services\Loyalty\LoyaltyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyController extends TenantController
{
    private const FEATURE = 'loyalty';

    public function __construct(private readonly LoyaltyService $loyalty)
    {
        parent::__construct();
    }

    /**
     * The organization's programme configuration (or the defaults template).
     */
    public function program(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

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

    public function updateProgram(Request $request): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);

        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'earn_rate' => ['required', 'numeric', 'min:0', 'max:10000'],
            'point_value' => ['required', 'numeric', 'min:0', 'max:10000'],
            'expiry_months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $program = $this->loyalty->saveProgram($this->organizationId(), $data);

        return successResponse($program, __('api.updated_success'));
    }

    /**
     * Loyalty balances of the caller's branches, richest first (up to 100).
     */
    public function accounts(): JsonResponse
    {
        $this->staff();
        $this->requireFeature(self::FEATURE);

        return successResponse($this->loyalty->accounts($this->organizationId(), $this->readBranchIds()));
    }

    /**
     * Manually adjust a customer's points (positive or negative).
     */
    public function adjust(Request $request, Customer $customer): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($customer);

        $data = $request->validate([
            'points' => ['required', 'numeric', 'not_in:0', 'between:-1000000,1000000'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $program = $this->requireSavedProgram();

        $account = $this->loyalty->adjust($customer, $program, (float) $data['points'], $data['note'] ?? null);

        return successResponse([
            'points_balance' => $account->points_balance,
            'lifetime_points' => $account->lifetime_points,
        ], __('api.updated_success'));
    }

    /**
     * Redeem a customer's points for wallet value.
     */
    public function redeem(Request $request, Customer $customer): JsonResponse
    {
        $this->requireManager();
        $this->requireFeature(self::FEATURE);
        $this->assertOwned($customer);

        $data = $request->validate([
            'points' => ['required', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        $program = $this->requireSavedProgram();

        $result = $this->loyalty->redeem($customer, $program, (float) $data['points']);

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

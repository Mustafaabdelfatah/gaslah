<?php

namespace App\Http\Controllers\API\Tenancy;

use App\Http\Requests\Reports\DateRangeRequest;
use App\Http\Requests\Tenancy\EmployeeCostRequest;
use App\Http\Requests\Tenancy\StoreBranchRequest;
use App\Http\Requests\Tenancy\UpdateBranchRequest;
use App\Http\Resources\Tenancy\OrganizationBranchResource;
use App\Models\Branch;
use App\Services\Reports\ReportRangeService;
use App\Services\Tenancy\OrganizationService;
use App\Services\Tenancy\OrgUserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The organization seen as a business: its branches, what each of them and each person
 * brought in, and what they cost.
 *
 * Every figure here is read across the whole organization rather than the caller's
 * current branch — comparing branches is the entire point of the screen, so narrowing
 * to one would answer a different question.
 */
class OrganizationController extends TenantController
{
    public function __construct(
        private readonly OrganizationService $organization,
        private readonly OrgUserService $users,
        private readonly ReportRangeService $ranges,
    ) {
        parent::__construct();
    }

    /*
    |--------------------------------------------------------------------------
    | Branches
    |--------------------------------------------------------------------------
    */
    public function branches(): JsonResponse
    {
        return successResponse(
            OrganizationBranchResource::collection($this->organization->branches($this->organizationId())),
        );
    }

    public function storeBranch(StoreBranchRequest $request): JsonResponse
    {
        $branch = $this->organization->createBranch($this->organizationId(), $request->validated());

        return successResponse(
            new OrganizationBranchResource($branch),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }

    public function updateBranch(UpdateBranchRequest $request, Branch $branch): JsonResponse
    {
        $this->assertOwned($branch);

        $updated = $this->organization->updateBranch($branch, $request->validated());

        return successResponse(new OrganizationBranchResource($updated), __('api.updated_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Performance and costs
    |--------------------------------------------------------------------------
    */
    public function branchPerformance(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->organization->branchPerformance(
            $this->organizationId(),
            $this->window($request),
        ));
    }

    public function employeePerformance(DateRangeRequest $request): JsonResponse
    {
        $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;

        return successResponse($this->organization->employeePerformance(
            $this->organizationId(),
            $this->window($request),
            $branchId,
        ));
    }

    public function costs(DateRangeRequest $request): JsonResponse
    {
        return successResponse($this->organization->costs($this->organizationId(), $this->window($request)));
    }

    /*
    |--------------------------------------------------------------------------
    | Declared salaries
    |--------------------------------------------------------------------------
    */
    public function setEmployeeCost(EmployeeCostRequest $request, int $user): JsonResponse
    {
        $target = $this->users->findInOrganization($this->organizationId(), $user);

        $cost = $this->organization->setEmployeeCost(
            $this->organizationId(),
            $target,
            $request->monthlySalary(),
            $request->note(),
        );

        return successResponse([
            'user_id' => $target->getKey(),
            'monthly_salary' => $cost->monthly_salary,
            'note' => $cost->note,
        ], __('api.updated_success'));
    }

    public function clearEmployeeCost(int $user): JsonResponse
    {
        $target = $this->users->findInOrganization($this->organizationId(), $user);

        $this->organization->clearEmployeeCost($this->organizationId(), $target);

        return successResponse(['user_id' => $target->getKey(), 'monthly_salary' => null], __('api.deleted_success'));
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * @return array<string, mixed>
     */
    private function window(Request $request): array
    {
        return $this->organization->window(
            $this->ranges->resolve($request->input('from'), $request->input('to')),
        );
    }
}

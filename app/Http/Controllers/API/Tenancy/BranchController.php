<?php

namespace App\Http\Controllers\API\Tenancy;

use App\Http\Resources\Tenancy\BranchResource;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

/**
 * The organization's own branches, for the header branch switcher. Read-only and
 * org-scoped — the switcher only narrows reads (via X-Branch-Id), never widens them.
 */
class BranchController extends TenantController
{
    public function index(): JsonResponse
    {
        $this->staff();

        $branches = Branch::query()
            ->where('organization_id', $this->organizationId())
            ->orderBy('id')
            ->get();

        return successResponse(BranchResource::collection($branches));
    }
}

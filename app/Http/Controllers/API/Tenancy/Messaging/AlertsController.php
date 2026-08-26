<?php

namespace App\Http\Controllers\API\Tenancy\Messaging;

use App\Http\Controllers\API\Tenancy\TenantController;
use App\Services\Messaging\AlertsService;
use Illuminate\Http\JsonResponse;

class AlertsController extends TenantController
{
    public function __construct(private readonly AlertsService $alerts)
    {
        parent::__construct();
    }

    public function index(): JsonResponse
    {
        $this->staff();

        return successResponse($this->alerts->build($this->readBranchIds(), $this->organizationId()));
    }
}

<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Resources\Auth\SignupResource;
use App\Services\Platform\TenantProvisioner;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Public self-service signup: provisions a tenant (organization + main branch +
 * super-admin + trial) and returns a staff session token.
 *
 * Whether signup is open at all is checked before the form request runs, so a closed
 * signup cannot be used to probe which addresses exist through the unique rule.
 */
class SignupController extends BaseController
{
    public function __construct(private readonly TenantProvisioner $provisioner)
    {
        parent::__construct();
    }

    public function store(SignupRequest $request): JsonResponse
    {
        $result = $this->provisioner->provisionWithSession($request->validated());

        return successResponse(
            new SignupResource($result['user'], $result['organization'], $result['token']),
            __('api.created_success'),
            Response::HTTP_CREATED,
        );
    }
}

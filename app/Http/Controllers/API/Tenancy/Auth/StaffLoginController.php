<?php

namespace App\Http\Controllers\API\Tenancy\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Tenancy\Auth\StaffLoginRequest;
use App\Http\Resources\Tenancy\Auth\StaffSessionResource;
use App\Services\Tenancy\Auth\StaffAuthService;
use Illuminate\Http\JsonResponse;

class StaffLoginController extends BaseController
{
    public function __construct(private readonly StaffAuthService $staffAuth)
    {
        parent::__construct();
    }

    public function __invoke(StaffLoginRequest $request): JsonResponse
    {
        $session = $this->staffAuth->login($request->validated());

        return successResponse(new StaffSessionResource($session), __('api.login_success'));
    }
}

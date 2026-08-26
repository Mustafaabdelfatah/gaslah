<?php

namespace App\Http\Controllers\API\Tenancy\Auth;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Tenancy\Auth\PlatformLoginRequest;
use App\Http\Resources\Tenancy\Auth\PlatformSessionResource;
use App\Services\Tenancy\Auth\PlatformAuthService;
use Illuminate\Http\JsonResponse;

class PlatformLoginController extends BaseController
{
    public function __construct(private readonly PlatformAuthService $platformAuth)
    {
        parent::__construct();
    }

    public function __invoke(PlatformLoginRequest $request): JsonResponse
    {
        $session = $this->platformAuth->login($request->validated());

        return successResponse(new PlatformSessionResource($session), __('api.login_success'));
    }
}

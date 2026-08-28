<?php

namespace App\Http\Controllers\API\Market;

use App\Http\Requests\Market\SupplierLoginRequest;
use App\Http\Resources\Market\MarketSupplierResource;
use App\Services\Market\SupplierAuthService;
use Illuminate\Http\JsonResponse;

/**
 * Sign-in and sign-out for the market supplier portal.
 */
class SupplierAuthController extends SupplierBaseController
{
    public function __construct(private readonly SupplierAuthService $auth)
    {
        parent::__construct();
    }

    public function login(SupplierLoginRequest $request): JsonResponse
    {
        $result = $this->auth->login($request->email(), $request->password());

        return successResponse([
            'supplier' => new MarketSupplierResource($result['supplier']),
            'token' => $result['token'],
        ], __('api.login_success'));
    }

    /**
     * Drop the token this request arrived with, leaving the supplier's other devices
     * signed in.
     */
    public function logout(): JsonResponse
    {
        $this->supplier()->currentAccessToken()?->delete();

        return successResponse(msg: __('api.user_logged_out'));
    }
}

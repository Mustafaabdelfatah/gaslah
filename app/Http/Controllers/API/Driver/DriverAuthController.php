<?php

namespace App\Http\Controllers\API\Driver;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Driver\DriverOtpRequest;
use App\Http\Requests\Driver\VerifyDriverOtpRequest;
use App\Services\Delivery\DriverAuthService;
use Illuminate\Http\JsonResponse;

class DriverAuthController extends BaseController
{
    public function __construct(private readonly DriverAuthService $auth)
    {
        parent::__construct();
    }

    public function requestOtp(DriverOtpRequest $request): JsonResponse
    {
        return successResponse($this->auth->requestOtp($request->phone()));
    }

    public function verifyOtp(VerifyDriverOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp($request->phone(), $request->code());

        return successResponse([
            'token' => $result['token'],
            'driver' => $result['driver'],
        ]);
    }
}

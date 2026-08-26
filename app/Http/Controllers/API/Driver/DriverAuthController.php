<?php

namespace App\Http\Controllers\API\Driver;

use App\Http\Controllers\API\BaseController;
use App\Services\Delivery\DriverAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DriverAuthController extends BaseController
{
    public function __construct(private readonly DriverAuthService $auth)
    {
        parent::__construct();
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return successResponse($this->auth->requestOtp($data['phone']));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'max:32'],
            'code' => ['required', 'string'],
        ]);

        $result = $this->auth->verifyOtp($data['phone'], $data['code']);

        return successResponse([
            'token' => $result['token'],
            'driver' => $result['driver'],
        ]);
    }
}

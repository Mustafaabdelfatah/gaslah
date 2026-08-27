<?php

namespace App\Http\Controllers\API\Portal;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Portal\PortalOtpRequest;
use App\Http\Requests\Portal\VerifyPortalOtpRequest;
use App\Services\Portal\PortalAuthService;
use Illuminate\Http\JsonResponse;

class PortalAuthController extends BaseController
{
    public function __construct(private readonly PortalAuthService $auth)
    {
        parent::__construct();
    }

    public function requestOtp(PortalOtpRequest $request): JsonResponse
    {
        return successResponse($this->auth->requestOtp($request->org(), $request->phone()));
    }

    public function verifyOtp(VerifyPortalOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp($request->org(), $request->phone(), $request->code());

        return successResponse([
            'token' => $result['token'],
            'customer' => $result['customer']->only('id', 'name', 'phone'),
            'org' => $result['organization']->only('id', 'name', 'slug'),
        ]);
    }

    public function branding(string $slug): JsonResponse
    {
        return successResponse($this->auth->branding($slug));
    }
}

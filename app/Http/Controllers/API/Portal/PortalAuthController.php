<?php

namespace App\Http\Controllers\API\Portal;

use App\Http\Controllers\API\BaseController;
use App\Services\Portal\PortalAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalAuthController extends BaseController
{
    public function __construct(private readonly PortalAuthService $auth)
    {
        parent::__construct();
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:32'],
            'org' => ['required', 'string', 'max:120'],
        ]);

        return successResponse($this->auth->requestOtp($data['org'], $data['phone']));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'phone' => ['required', 'string', 'min:6', 'max:32'],
            'org' => ['required', 'string', 'max:120'],
            'code' => ['required', 'string'],
        ]);

        $result = $this->auth->verifyOtp($data['org'], $data['phone'], $data['code']);

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

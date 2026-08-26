<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\API\BaseController;
use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateAuthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AffiliateAuthController extends BaseController
{
    public function __construct(private readonly AffiliateAuthService $auth)
    {
        parent::__construct();
    }

    public function register(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'email' => ['required', 'email', 'max:255', 'unique:affiliates,email'],
            'phone' => ['required', 'string', 'min:6', 'max:32', 'unique:affiliates,phone'],
        ]);

        $result = $this->auth->register($data);

        return successResponse(['token' => $result['token'], 'affiliate' => $result['affiliate']->only('id', 'name', 'code')], __('api.created_success'), 201);
    }

    public function requestOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32']]);

        return successResponse($this->auth->requestOtp($data['phone']));
    }

    public function verifyOtp(Request $request): JsonResponse
    {
        $data = $request->validate(['phone' => ['required', 'string', 'max:32'], 'code' => ['required', 'string']]);

        $result = $this->auth->verifyOtp($data['phone'], $data['code']);

        return successResponse(['token' => $result['token'], 'affiliate' => $result['affiliate']->only('id', 'name', 'code')]);
    }

    /**
     * Public referral landing resolver.
     */
    public function landing(string $code): JsonResponse
    {
        $affiliate = Affiliate::query()->where('code', $code)->where('is_active', true)->first();

        if ($affiliate === null) {
            return successResponse(['found' => false]);
        }

        return successResponse([
            'found' => true,
            'affiliate_name' => $affiliate->name,
            'code' => $affiliate->code,
            'signup_url' => rtrim((string) config('services.payment.web_url'), '/').'/signup?ref='.$affiliate->code,
        ]);
    }
}

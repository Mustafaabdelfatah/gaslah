<?php

namespace App\Http\Controllers\API\Affiliate;

use App\Http\Controllers\API\BaseController;
use App\Http\Requests\Affiliate\AffiliateOtpRequest;
use App\Http\Requests\Affiliate\RegisterAffiliateRequest;
use App\Http\Requests\Affiliate\VerifyAffiliateOtpRequest;
use App\Models\Affiliate;
use App\Services\Affiliate\AffiliateAuthService;
use Illuminate\Http\JsonResponse;

class AffiliateAuthController extends BaseController
{
    public function __construct(private readonly AffiliateAuthService $auth)
    {
        parent::__construct();
    }

    public function register(RegisterAffiliateRequest $request): JsonResponse
    {
        $result = $this->auth->register($request->validated());

        return successResponse(['token' => $result['token'], 'affiliate' => $result['affiliate']->only('id', 'name', 'code')], __('api.created_success'), 201);
    }

    public function requestOtp(AffiliateOtpRequest $request): JsonResponse
    {
        return successResponse($this->auth->requestOtp($request->phone()));
    }

    public function verifyOtp(VerifyAffiliateOtpRequest $request): JsonResponse
    {
        $result = $this->auth->verifyOtp($request->phone(), $request->code());

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

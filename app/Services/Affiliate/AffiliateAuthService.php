<?php

namespace App\Services\Affiliate;

use App\Enum\Global\OtpPurposeEnum;
use App\Enum\Global\TokenKindEnum;
use App\Models\Affiliate;
use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone + OTP authentication for the affiliate surface — its own space, separate from
 * organization customers (orgId is null, purpose AffiliateLogin). An unknown phone gets the
 * same success shape with no code sent (anti-enumeration).
 */
class AffiliateAuthService
{
    private const CODE_TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const TOKEN_TTL_DAYS = 30;

    /**
     * Self-service registration; issues a token immediately.
     *
     * @param  array<string, mixed>  $data
     * @return array{token: string, affiliate: Affiliate}
     */
    public function register(array $data): array
    {
        $affiliate = Affiliate::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $this->normalize($data['phone']),
            'code' => $this->generateUniqueCode($data['name']),
        ]);

        return ['token' => $this->issueToken($affiliate), 'affiliate' => $affiliate];
    }

    /**
     * @return array{sent: bool, delivered?: bool, message?: string, dev_code?: string}
     */
    public function requestOtp(string $phone): array
    {
        if (! $this->canDeliver()) {
            return ['sent' => false, 'message' => __('api.otp_service_unavailable')];
        }

        $phone = $this->normalize($phone);
        $affiliate = $this->activeAffiliate($phone);

        if ($affiliate === null) {
            return ['sent' => true, 'delivered' => true];
        }

        $code = $this->generateCode();

        OtpCode::query()->create([
            'organization_id' => null,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => OtpPurposeEnum::AffiliateLogin->value,
            'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
            'attempts' => 0,
        ]);

        return array_filter([
            'sent' => true,
            'delivered' => true,
            'dev_code' => app()->environment('local', 'testing') ? $code : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * @return array{token: string, affiliate: Affiliate}
     */
    public function verifyOtp(string $phone, string $code): array
    {
        $phone = $this->normalize($phone);

        $otp = OtpCode::query()->activeFor(null, $phone, OtpPurposeEnum::AffiliateLogin)->first();

        if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $affiliate = $this->activeAffiliate($phone);
        if ($affiliate === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $consumed = OtpCode::query()->whereKey($otp->getKey())->whereNull('consumed_at')->update(['consumed_at' => Carbon::now()]);
        if ($consumed !== 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        return ['token' => $this->issueToken($affiliate), 'affiliate' => $affiliate];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    public function generateUniqueCode(string $name): string
    {
        $base = Str::upper(Str::slug($name, '')) ?: 'AFF';
        $base = Str::substr($base, 0, 8);

        do {
            $code = $base.Str::upper(Str::random(4));
        } while (Affiliate::query()->where('code', $code)->exists());

        return $code;
    }

    private function activeAffiliate(string $phone): ?Affiliate
    {
        return Affiliate::query()->where('phone', $phone)->where('is_active', true)->first();
    }

    private function issueToken(Affiliate $affiliate): string
    {
        $token = $affiliate->createToken(TokenKindEnum::Affiliate->value, ['*'], Carbon::now()->addDays(self::TOKEN_TTL_DAYS));

        $token->accessToken->forceFill([
            'meta' => ['kind' => TokenKindEnum::Affiliate->value, 'affiliate_id' => $affiliate->getKey()],
        ])->save();

        return $token->plainTextToken;
    }

    private function normalize(string $phone): string
    {
        return preg_replace('/[\s-]+/', '', trim($phone)) ?? $phone;
    }

    private function generateCode(): string
    {
        $fixed = config('project.otp.default');

        if (app()->environment('local', 'testing') && $fixed) {
            return (string) $fixed;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    private function canDeliver(): bool
    {
        return app()->environment('local', 'testing');
    }
}

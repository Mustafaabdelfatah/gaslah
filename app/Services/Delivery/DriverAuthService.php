<?php

namespace App\Services\Delivery;

use App\Enum\Global\OtpPurposeEnum;
use App\Enum\Global\TokenKindEnum;
use App\Models\Driver;
use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone + OTP authentication for the driver app — a surface of its own, separate from
 * staff and customers.
 *
 * An unknown phone is answered with the same success shape as a known one and no code is
 * sent, so the public endpoint cannot be turned into an oracle for enumerating driver
 * numbers. The phone is unique system-wide, so it resolves a single active driver; login
 * fails closed on anything else.
 */
class DriverAuthService
{
    private const CODE_TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const TOKEN_TTL_DAYS = 30;

    /**
     * Send a login code to a driver's phone.
     *
     * @return array{sent: bool, delivered?: bool, message?: string, dev_code?: string}
     */
    public function requestOtp(string $phone): array
    {
        $phone = $this->normalize($phone);

        // Configuration failure is reported independently of the phone.
        if (! $this->canDeliver()) {
            return ['sent' => false, 'message' => __('api.otp_service_unavailable')];
        }

        $driver = $this->uniqueActiveDriver($phone);

        // Unknown phone: answer exactly like success without sending anything.
        if ($driver === null) {
            return ['sent' => true, 'delivered' => true];
        }

        $code = $this->generateCode();

        OtpCode::query()->create([
            'organization_id' => null,
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => OtpPurposeEnum::DriverLogin->value,
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
     * Verify a code and issue a 30-day driver token.
     *
     * @return array{token: string, driver: Driver}
     */
    public function verifyOtp(string $phone, string $code): array
    {
        $phone = $this->normalize($phone);

        $otp = OtpCode::query()
            ->activeFor(null, $phone, OtpPurposeEnum::DriverLogin)
            ->first();

        if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $driver = $this->uniqueActiveDriver($phone);

        if ($driver === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        // Atomic single-use consumption.
        $consumed = OtpCode::query()
            ->whereKey($otp->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        if ($consumed !== 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        return ['token' => $this->issueToken($driver), 'driver' => $driver];
    }

    /**
     * The single active driver for a phone, or null when none or ambiguous.
     *
     * The phone is unique system-wide, so this is unambiguous by construction; the count
     * guard keeps it fail-closed should that ever be violated.
     */
    public function uniqueActiveDriver(string $phone): ?Driver
    {
        $drivers = Driver::query()->where('phone', $phone)->where('is_active', true)->limit(2)->get();

        return $drivers->count() === 1 ? $drivers->first() : null;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function issueToken(Driver $driver): string
    {
        $token = $driver->createToken(
            TokenKindEnum::Driver->value,
            ['*'],
            Carbon::now()->addDays(self::TOKEN_TTL_DAYS),
        );

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Driver->value,
                'driver_id' => $driver->getKey(),
                'organization_id' => $driver->organization_id ?? $driver->branch?->organization_id,
            ],
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
        // The real provider check arrives with the messaging module; until then only
        // development environments may issue codes.
        return app()->environment('local', 'testing');
    }
}

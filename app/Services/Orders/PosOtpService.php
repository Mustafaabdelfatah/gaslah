<?php

namespace App\Services\Orders;

use App\Enum\Global\OtpPurposeEnum;
use App\Models\Customer;
use App\Models\OtpCode;
use App\Models\PosOtpProof;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Customer-present verification for wallet payments.
 *
 * Before any stored value is drawn, the present customer proves consent with an OTP
 * sent to their phone. Verification issues a short-lived one-shot proof that the
 * payment flow burns atomically the instant before it moves money — so a replayed
 * proof can never cause a second debit.
 */
class PosOtpService
{
    private const CODE_TTL_MINUTES = 5;

    private const PROOF_TTL_MINUTES = 10;

    private const MAX_ATTEMPTS = 5;

    /**
     * Send a verification code to a customer's phone.
     *
     * @return array{sent: bool, dev_code?: string}
     */
    public function request(Customer $customer): array
    {
        if ($customer->phone === '' || $customer->phone === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.customer_has_no_phone'));
        }

        // Fail closed outside local/testing until a real messaging provider exists
        // (the messaging module): a guessable dev code must never leave development.
        if (! $this->canDeliver()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_service_unavailable'));
        }

        $code = $this->generateCode();

        OtpCode::query()->create([
            'organization_id' => $customer->organization_id,
            'phone' => $customer->phone,
            'code_hash' => Hash::make($code),
            'purpose' => OtpPurposeEnum::PosWallet->value,
            'expires_at' => Carbon::now()->addMinutes(self::CODE_TTL_MINUTES),
            'attempts' => 0,
        ]);

        // A real provider send lands in the messaging module; here the code is only
        // surfaced in local/testing.
        return array_filter([
            'sent' => true,
            'dev_code' => app()->environment('local', 'testing') ? $code : null,
        ], fn ($value) => $value !== null);
    }

    /**
     * Verify a code and, on success, issue a one-shot proof token.
     *
     * @return array{proof_token: string}
     */
    public function verify(Customer $customer, string $code): array
    {
        $otp = OtpCode::query()
            ->activeFor($customer->organization_id, $customer->phone, OtpPurposeEnum::PosWallet)
            ->first();

        if ($otp === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        if ($otp->expires_at->isPast()) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_expired'));
        }

        if ($otp->attempts >= self::MAX_ATTEMPTS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.otp_max_attempts'));
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        // Atomic single-use consumption: only the update that finds consumed_at still
        // null succeeds, so one code cannot mint two proofs.
        $consumed = OtpCode::query()
            ->whereKey($otp->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        if ($consumed !== 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        return ['proof_token' => $this->issueProof($customer)];
    }

    /**
     * Burn a consent proof, returning true only to the caller that consumed it.
     *
     * This is the atomic check-and-burn that must run before money moves: the single
     * delete that removes the still-present row wins; every other caller gets false.
     */
    public function reserve(string $proofToken, Customer $customer): bool
    {
        $deleted = PosOtpProof::query()
            ->where('token_hash', $this->hashToken($proofToken))
            ->where('organization_id', $customer->organization_id)
            ->where('customer_id', $customer->getKey())
            ->where('expires_at', '>', Carbon::now())
            ->delete();

        return $deleted === 1;
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    private function issueProof(Customer $customer): string
    {
        $token = Str::random(48);

        PosOtpProof::query()->create([
            'token_hash' => $this->hashToken($token),
            'organization_id' => $customer->organization_id,
            'customer_id' => $customer->getKey(),
            'expires_at' => Carbon::now()->addMinutes(self::PROOF_TTL_MINUTES),
        ]);

        return $token;
    }

    private function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    private function generateCode(): string
    {
        $fixed = config('project.otp.default');

        if (app()->environment('local', 'testing') && $fixed) {
            return (string) $fixed;
        }

        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Whether a real send channel exists. Local and testing are always allowed.
     */
    private function canDeliver(): bool
    {
        // The real provider check arrives with the messaging module; until then only
        // development environments may issue codes.
        return app()->environment('local', 'testing');
    }
}

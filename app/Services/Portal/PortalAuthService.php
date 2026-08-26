<?php

namespace App\Services\Portal;

use App\Enum\Global\OtpPurposeEnum;
use App\Enum\Global\TokenKindEnum;
use App\Models\Customer;
use App\Models\Organization;
use App\Models\OtpCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Phone + OTP authentication for the customer portal — a surface of its own, scoped to
 * one organization.
 *
 * Portal codes are minted with their own purpose (PortalLogin), so a code a cashier asked
 * the customer to read out to approve a till debit (PosWallet) can never double as a
 * 30-day portal session. An unknown organization or phone always returns the same shape
 * as success, so neither can be enumerated through this public endpoint.
 */
class PortalAuthService
{
    private const CODE_TTL_MINUTES = 5;

    private const MAX_ATTEMPTS = 5;

    private const TOKEN_TTL_DAYS = 30;

    /**
     * Send a login code to a registered customer's phone.
     *
     * @return array{sent: bool, delivered?: bool, message?: string, dev_code?: string}
     */
    public function requestOtp(string $org, string $phone): array
    {
        $organization = $this->resolveOrganization($org);

        // Do not reveal whether the organization exists.
        if ($organization === null) {
            return ['sent' => false];
        }

        if (! $this->canDeliver()) {
            return ['sent' => false, 'message' => __('api.otp_service_unavailable')];
        }

        $phone = $this->normalize($phone);
        $customer = $this->findCustomer($organization->getKey(), $phone);

        // Unknown phone: mirror success exactly, send nothing, store nothing.
        if ($customer === null) {
            return ['sent' => true, 'delivered' => true];
        }

        $code = $this->generateCode();

        OtpCode::query()->create([
            'organization_id' => $organization->getKey(),
            'phone' => $phone,
            'code_hash' => Hash::make($code),
            'purpose' => OtpPurposeEnum::PortalLogin->value,
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
     * Verify a code and issue a 30-day customer token.
     *
     * @return array{token: string, customer: Customer, organization: Organization}
     */
    public function verifyOtp(string $org, string $phone, string $code): array
    {
        $organization = $this->resolveOrganization($org);

        // A uniform failure message across every early failure (no organization, no
        // customer, wrong code) so none can be distinguished.
        if ($organization === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $phone = $this->normalize($phone);

        $otp = OtpCode::query()
            ->activeFor($organization->getKey(), $phone, OtpPurposeEnum::PortalLogin)
            ->first();

        if ($otp === null || $otp->expires_at->isPast() || $otp->attempts >= self::MAX_ATTEMPTS) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        if (! Hash::check($code, $otp->code_hash)) {
            $otp->increment('attempts');
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $customer = $this->findCustomer($organization->getKey(), $phone);

        if ($customer === null) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        $consumed = OtpCode::query()
            ->whereKey($otp->getKey())
            ->whereNull('consumed_at')
            ->update(['consumed_at' => Carbon::now()]);

        if ($consumed !== 1) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.invalid_otp'));
        }

        return [
            'token' => $this->issueToken($customer, $organization),
            'customer' => $customer,
            'organization' => $organization,
        ];
    }

    /**
     * Public, non-sensitive branding for the portal sign-in screen. Same shape whether
     * the slug exists or not.
     *
     * @return array<string, mixed>
     */
    public function branding(string $slug): array
    {
        $organization = Organization::query()->where('slug', $slug)->first();

        if ($organization === null) {
            return ['found' => false];
        }

        $settings = is_array($organization->settings) ? ($organization->settings['portal'] ?? []) : [];

        return [
            'found' => true,
            'name' => $organization->name,
            'logo_url' => $organization->logo_url,
            'brand_primary' => $organization->brand_primary,
            'show_offers' => (bool) ($settings['show_offers'] ?? false),
            'terms_url' => $settings['terms_url'] ?? null,
            'privacy_url' => $settings['privacy_url'] ?? null,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */
    public function resolveOrganization(string $org): ?Organization
    {
        return Organization::query()
            ->where('slug', $org)
            ->when(ctype_digit($org), fn ($q) => $q->orWhere('id', (int) $org))
            ->first();
    }

    private function findCustomer(int $organizationId, string $phone): ?Customer
    {
        return Customer::query()->where('organization_id', $organizationId)->where('phone', $phone)->first();
    }

    private function issueToken(Customer $customer, Organization $organization): string
    {
        $token = $customer->createToken(
            TokenKindEnum::Customer->value,
            ['*'],
            Carbon::now()->addDays(self::TOKEN_TTL_DAYS),
        );

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Customer->value,
                'customer_id' => $customer->getKey(),
                'organization_id' => $organization->getKey(),
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
        return app()->environment('local', 'testing');
    }
}

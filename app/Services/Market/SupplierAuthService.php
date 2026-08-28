<?php

namespace App\Services\Market;

use App\Enum\Global\TokenKindEnum;
use App\Enum\Tenancy\SecuritySurfaceEnum;
use App\Models\MarketSupplier;
use App\Services\Auth\SecurityLogService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs a market supplier into their portal.
 *
 * Their own surface: a supplier belongs to no tenant and sells to many, so this is an
 * email and password rather than the phone-and-code the driver and customer apps use.
 */
class SupplierAuthService
{
    private const TOKEN_DAYS = 30;

    public function __construct(private readonly SecurityLogService $securityLog) {}

    /**
     * @return array{supplier: MarketSupplier, token: string}
     */
    public function login(string $email, string $password): array
    {
        $surface = SecuritySurfaceEnum::Supplier;
        $email = Str::lower(trim($email));

        // Refuse before checking the password, so a locked address cannot be probed.
        $this->securityLog->ensureNotLocked($surface, $email);

        $supplier = MarketSupplier::query()->where('email', $email)->first();

        // One message for both an unknown address and a wrong password: telling them apart
        // turns this into an account-enumeration oracle.
        if ($supplier === null || ! Hash::check($password, $supplier->password)) {
            $this->securityLog->recordFailure($surface, $email, 'bad_credentials');
            abort(Response::HTTP_UNAUTHORIZED, __('api.invalid_email_and_password'));
        }

        // A rejected supplier is turned away entirely. One awaiting review or suspended
        // may still sign in — the portal has to be able to tell them why they are not
        // selling, and let them keep their catalogue up to date meanwhile.
        abort_unless($supplier->status->canSignIn(), Response::HTTP_FORBIDDEN, __('api.market_supplier_rejected'));

        // No success row: the security log records attempts against User accounts, and a
        // supplier is not one. The lockout counter works off failures, which is what
        // protects this surface.

        return ['supplier' => $supplier, 'token' => $this->issueToken($supplier)];
    }

    private function issueToken(MarketSupplier $supplier): string
    {
        $token = $supplier->createToken(
            TokenKindEnum::Supplier->value,
            ['*'],
            now()->addDays(self::TOKEN_DAYS),
        );

        $token->accessToken->forceFill([
            'meta' => ['kind' => TokenKindEnum::Supplier->value, 'supplier_id' => $supplier->getKey()],
        ])->save();

        return $token->plainTextToken;
    }
}

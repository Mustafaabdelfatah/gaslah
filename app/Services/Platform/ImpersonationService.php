<?php

namespace App\Services\Platform;

use App\Enum\Global\TokenKindEnum;
use App\Enum\Platform\PlatformAuditActionEnum;
use App\Enum\Tenancy\StaffRoleEnum;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a platform owner enter a tenant as one of its own staff, to see what the tenant
 * sees when supporting them.
 *
 * The session is deliberately weak: it expires on its own, it is stamped with who opened
 * it, and it can be killed from the server. The operator's own token is never touched, so
 * ending impersonation cannot log them out of the console.
 */
class ImpersonationService
{
    /**
     * Short enough that an unattended session on a shared machine dies by itself.
     */
    private const LIFETIME_MINUTES = 30;

    public function __construct(private readonly PlatformAuditService $audit) {}

    /**
     * Open an impersonated session into a tenant.
     *
     * @return array{token: string, expires_at: Carbon, user: User}
     */
    public function start(Organization $organization, User $admin): array
    {
        $target = $this->impersonationTarget($organization);

        abort_if($target === null, Response::HTTP_UNPROCESSABLE_ENTITY, __('api.impersonation_no_target'));

        $branchId = $target->userBranches()
            ->whereHas('branch', fn ($branch) => $branch->where('organization_id', $organization->getKey()))
            ->value('branch_id');

        $expiresAt = Carbon::now()->addMinutes(self::LIFETIME_MINUTES);
        $token = $target->createToken(TokenKindEnum::Staff->value, ['*'], $expiresAt);

        $token->accessToken->forceFill([
            'meta' => [
                'kind' => TokenKindEnum::Staff->value,
                'organization_id' => $organization->getKey(),
                'branch_id' => $branchId,
                // Who opened this session. Present only on impersonated tokens, which is
                // what lets the audit trail and the UI tell them apart.
                'impersonated_by' => $admin->getKey(),
            ],
        ])->save();

        $this->audit->log($admin, PlatformAuditActionEnum::Impersonate, $organization, [
            'user_id' => $target->getKey(),
            'expires_at' => $expiresAt->toIso8601String(),
        ]);

        return ['token' => $token->plainTextToken, 'expires_at' => $expiresAt, 'user' => $target];
    }

    /**
     * Kill every live impersonated session this admin opened.
     *
     * Without this a borrowed staff session outlives the support call — the operator
     * closes the tab and the token keeps working on whatever machine it was minted for.
     *
     * @return int how many sessions were ended
     */
    public function stop(User $admin): int
    {
        return PersonalAccessToken::query()
            ->where('name', TokenKindEnum::Staff->value)
            ->where('meta->impersonated_by', $admin->getKey())
            ->delete();
    }

    /**
     * The privilege rank of a membership's role, however the model hands it over — the
     * column is enum-cast, so it may already be an instance.
     */
    private function rankOf(mixed $role): int
    {
        $resolved = $role instanceof StaffRoleEnum ? $role : StaffRoleEnum::tryFrom((string) $role);

        return $resolved?->rank() ?? 0;
    }

    /**
     * Pick who to enter as: an active member of the tenant, preferring its general manager.
     *
     * Platform admins are skipped on purpose. Their own account would be re-stamped as a
     * platform admin by the guard on the next request, quietly turning a restricted,
     * expiring support session into a full console one.
     */
    private function impersonationTarget(Organization $organization): ?User
    {
        $candidates = User::query()
            ->active()
            ->inOrganization($organization->getKey())
            ->where('is_platform_owner', false)
            ->with(['userBranches' => fn ($q) => $q->whereHas('branch', fn ($b) => $b->where('organization_id', $organization->getKey()))])
            ->get();

        return $candidates
            ->sortByDesc(fn (User $user) => $user->userBranches
                ->map(fn ($membership) => $this->rankOf($membership->role))
                ->max() ?? 0)
            ->first();
    }
}

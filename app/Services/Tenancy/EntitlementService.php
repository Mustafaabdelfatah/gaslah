<?php

namespace App\Services\Tenancy;

use App\Models\Branch;
use App\Models\Feature;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides what an organization is entitled to: which features are on, whether the
 * account may still be written to, and how many branches and seats it may use.
 *
 * An organization carrying no subscription is treated as fully entitled with
 * unlimited seats. That keeps every business already using the product working
 * exactly as before; enforcement begins only once an operator suspends the account
 * or places it on a plan.
 */
class EntitlementService
{
    /**
     * Stand-in for "no ceiling", used while an organization is un-metered.
     */
    public const UNLIMITED = 999999;

    /**
     * @var Collection<int, Feature>|null
     */
    private ?Collection $catalogue = null;

    /**
     * Feature keys currently enabled for the organization.
     *
     * @return array<int, string>
     */
    public function features(Organization $organization): array
    {
        $catalogue = $this->catalogue();

        // A suspended account is readable but inert: only the base product survives.
        if (! $this->isActive($organization)) {
            return $catalogue->where('is_core', true)->pluck('key')->all();
        }

        $enabled = $catalogue->pluck('key')->all();

        return $this->applyOverrides($organization, $enabled);
    }

    public function hasFeature(Organization $organization, string $key): bool
    {
        return in_array($key, $this->features($organization), true);
    }

    /**
     * Refuse the request when the feature is not part of the organization's plan.
     */
    public function requireFeature(Organization $organization, string $key): void
    {
        if (! $this->hasFeature($organization, $key)) {
            abort(Response::HTTP_FORBIDDEN, __('api.feature_not_enabled'));
        }
    }

    /**
     * Whether the account may be written to at all.
     */
    public function isActive(Organization $organization): bool
    {
        return ! $organization->is_suspended && ! $organization->isArchived();
    }

    /**
     * Refuse writes on an inactive account while leaving reads alone.
     *
     * Answered with Payment Required rather than Forbidden: the caller's credentials
     * are fine, it is the subscription that is not.
     */
    public function requireActive(Organization $organization): void
    {
        if (! $this->isActive($organization)) {
            abort(Response::HTTP_PAYMENT_REQUIRED, __('api.subscription_not_active'));
        }
    }

    public function maxBranches(Organization $organization): int
    {
        return $organization->max_branches_override ?? self::UNLIMITED;
    }

    public function maxUsers(Organization $organization): int
    {
        return $organization->max_users_override ?? self::UNLIMITED;
    }

    public function usedBranches(Organization $organization): int
    {
        return Branch::query()
            ->where('organization_id', $organization->getKey())
            ->count();
    }

    /**
     * Seats in use.
     *
     * A disabled account keeps its branch memberships for audit but does not hold a
     * seat, otherwise a business could not hire a replacement without first erasing
     * the person who left.
     */
    public function usedUsers(Organization $organization): int
    {
        return User::query()
            ->active()
            ->inOrganization($organization->getKey())
            ->count();
    }

    public function assertBranchQuota(Organization $organization): void
    {
        if ($this->usedBranches($organization) >= $this->maxBranches($organization)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.branch_quota_reached'));
        }
    }

    public function assertUserQuota(Organization $organization): void
    {
        if ($this->usedUsers($organization) >= $this->maxUsers($organization)) {
            abort(Response::HTTP_UNPROCESSABLE_ENTITY, __('api.user_quota_reached'));
        }
    }

    /**
     * @return array{
     *     active: bool,
     *     features: array<int, string>,
     *     limits: array{branches: int, users: int},
     *     usage: array{branches: int, users: int}
     * }
     */
    public function snapshot(Organization $organization): array
    {
        return [
            'active' => $this->isActive($organization),
            'features' => $this->features($organization),
            'limits' => [
                'branches' => $this->maxBranches($organization),
                'users' => $this->maxUsers($organization),
            ],
            'usage' => [
                'branches' => $this->usedBranches($organization),
                'users' => $this->usedUsers($organization),
            ],
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Helper Methods
    |--------------------------------------------------------------------------
    */

    /**
     * Apply the per-organization on/off switches an operator has set.
     *
     * Only gated features can be moved: a core feature is part of the base product
     * and an override naming one is ignored rather than honoured.
     *
     * @param  array<int, string>  $enabled
     * @return array<int, string>
     */
    private function applyOverrides(Organization $organization, array $enabled): array
    {
        $overrides = $organization->feature_overrides;

        if (! is_array($overrides) || $overrides === []) {
            return array_values($enabled);
        }

        $gated = $this->catalogue()->where('is_core', false)->pluck('key')->all();
        $enabled = array_flip($enabled);

        foreach ($overrides as $key => $isEnabled) {
            if (! in_array($key, $gated, true)) {
                continue;
            }

            if ($isEnabled) {
                $enabled[$key] = true;

                continue;
            }

            unset($enabled[$key]);
        }

        return array_values(array_keys($enabled));
    }

    /**
     * @return Collection<int, Feature>
     */
    private function catalogue(): Collection
    {
        return $this->catalogue ??= Feature::query()->ordered()->get();
    }
}

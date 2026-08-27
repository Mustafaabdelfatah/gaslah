<?php

namespace App\Services\Platform;

use App\Enum\Platform\PlatformAuditActionEnum;
use App\Http\Resources\Platform\PlatformEventResource;
use App\Http\Resources\Platform\TenantDetailResource;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Models\User;
use App\Services\Tenancy\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * The platform operator's view of a single tenant, and the controls it can apply.
 *
 * Everything that needs more than a model write lives here: composing the drill-down,
 * deciding whether a tenant is at risk, and pairing each control with its audit entry.
 */
class TenantDirectoryService
{
    /**
     * A tenant is at risk once an otherwise-live account has gone this long without an order.
     */
    private const AT_RISK_QUIET_DAYS = 30;

    /**
     * How many lifecycle events the drill-down shows.
     */
    private const EVENT_HISTORY = 20;

    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PlatformAuditService $audit,
    ) {}

    /**
     * The tenant drill-down: the tenant itself, its entitlements, and its recent
     * subscription lifecycle.
     *
     * @return array<string, mixed>
     */
    public function detail(Organization $organization): array
    {
        $organization->loadMissing('platformSubscription.plan')->loadCount('branches');

        return [
            'tenant' => new TenantDetailResource($organization),
            'entitlements' => $this->entitlements->snapshot($organization),
            'at_risk' => $this->isAtRisk($organization),
            'recent_events' => PlatformEventResource::collection($this->recentEvents($organization)),
        ];
    }

    /**
     * The staff of one tenant, with the roles they hold across its branches.
     *
     * @return Builder<User>
     */
    public function staffQuery(Organization $organization)
    {
        $branchIds = $organization->branches()->pluck('id');

        return User::query()
            ->whereHas('branches', fn ($q) => $q->whereIn('branches.id', $branchIds))
            ->with(['branches' => fn ($q) => $q->whereIn('branches.id', $branchIds)]);
    }

    /**
     * Suspend or reactivate a tenant, leaving the audit entry the action requires.
     */
    public function setSuspended(Organization $organization, User $admin, bool $suspended, ?string $reason = null): Organization
    {
        $organization->forceFill(['is_suspended' => $suspended])->save();

        $this->audit->log(
            $admin,
            $suspended ? PlatformAuditActionEnum::Suspend : PlatformAuditActionEnum::Reactivate,
            $organization,
            array_filter(['reason' => $reason]),
        );

        return $organization;
    }

    /**
     * Apply the operator's entitlement overrides. Only the supplied keys are written.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed> the resulting entitlements snapshot
     */
    public function applyEntitlements(Organization $organization, User $admin, array $overrides): array
    {
        if ($overrides !== []) {
            $organization->forceFill($overrides)->save();
        }

        $this->audit->log($admin, PlatformAuditActionEnum::UpdateEntitlements, $organization, $overrides);

        return $this->entitlements->snapshot($organization->refresh());
    }

    /**
     * A live account that has taken no order in the quiet window is at risk of churning.
     */
    private function isAtRisk(Organization $organization): bool
    {
        if (! $this->entitlements->isActive($organization)) {
            return false;
        }

        return ! $organization->orders()
            ->where('created_at', '>=', Carbon::now()->subDays(self::AT_RISK_QUIET_DAYS))
            ->exists();
    }

    /**
     * @return Collection<int, PlatformEvent>
     */
    private function recentEvents(Organization $organization)
    {
        return PlatformEvent::query()
            ->where('organization_id', $organization->getKey())
            ->latest('created_at')
            ->limit(self::EVENT_HISTORY)
            ->get();
    }
}

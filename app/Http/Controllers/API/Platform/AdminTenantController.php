<?php

namespace App\Http\Controllers\API\Platform;

use App\Enum\Orders\OrderStatusEnum;
use App\Enum\Platform\PlatformAuditActionEnum;
use App\Enum\Tenancy\PlatformPermissionEnum;
use App\Models\Feature;
use App\Models\Order;
use App\Models\Organization;
use App\Models\PlatformEvent;
use App\Services\Platform\PlatformAuditService;
use App\Services\Tenancy\EntitlementService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The platform operator's cross-tenant directory and per-tenant controls
 * (manage_tenants). The reserved platform-books organization is excluded from the
 * listing but still reachable by id.
 */
class AdminTenantController extends PlatformBaseController
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PlatformAuditService $audit,
    ) {
        parent::__construct();
    }

    public function index(Request $request): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageTenants);

        $search = trim((string) $request->query('search', ''));

        $tenants = Organization::query()
            ->tenantsOnly()
            ->with('platformSubscription.plan')
            ->withCount('branches')
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->orderByDesc('id')
            ->paginate(20);

        $orgIds = collect($tenants->items())->pluck('id')->all();
        $orderStats = $this->orderStatsFor($orgIds);
        $userCounts = $this->userCountsFor($orgIds);

        $tenants->getCollection()->transform(function (Organization $org) use ($orderStats, $userCounts) {
            $stats = $orderStats[$org->getKey()] ?? ['orders' => 0, 'revenue' => 0.0];

            return [
                'id' => $org->getKey(),
                'name' => $org->name,
                'slug' => $org->slug,
                'is_suspended' => (bool) $org->is_suspended,
                'is_archived' => $org->isArchived(),
                'branches_count' => $org->branches_count,
                'users_count' => $userCounts[$org->getKey()] ?? 0,
                'orders_count' => $stats['orders'],
                'revenue' => $stats['revenue'],
                'plan_name' => $org->platformSubscription?->plan?->name,
                'status' => $org->platformSubscription?->displayStatus() ?? 'grandfathered',
            ];
        });

        return successResponse($tenants);
    }

    public function show(Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageTenants);

        $subscription = $organization->platformSubscription?->load('plan');
        $stats = $this->orderStatsFor([$organization->getKey()])[$organization->getKey()] ?? ['orders' => 0, 'revenue' => 0.0];

        $recentOrders = Order::query()
            ->whereIn('branch_id', $organization->branches()->pluck('id'))
            ->where('status', '!=', OrderStatusEnum::Cancelled->value)
            ->where('created_at', '>=', Carbon::now()->subDays(30))
            ->count();

        return successResponse([
            'organization' => [
                'id' => $organization->getKey(),
                'name' => $organization->name,
                'slug' => $organization->slug,
                'is_suspended' => (bool) $organization->is_suspended,
                'is_archived' => $organization->isArchived(),
                'archived_at' => $organization->archived_at,
                'created_at' => $organization->created_at,
            ],
            'subscription' => $subscription === null ? null : [
                ...$subscription->toArray(),
                'display_status' => $subscription->displayStatus(),
            ],
            'entitlements' => $this->entitlements->snapshot($organization),
            'stats' => [
                'orders_count' => $stats['orders'],
                'revenue' => $stats['revenue'],
                'branches' => $organization->branches()->count(),
                'at_risk' => $this->entitlements->isActive($organization) && $recentOrders === 0,
            ],
            'recent_events' => PlatformEvent::query()
                ->where('organization_id', $organization->getKey())
                ->latest('created_at')
                ->limit(20)
                ->get(),
        ]);
    }

    public function suspend(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageTenants);

        $data = $request->validate([
            'suspended' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $suspended = (bool) $data['suspended'];
        $organization->forceFill(['is_suspended' => $suspended])->save();

        $this->audit->log(
            $this->admin(),
            $suspended ? PlatformAuditActionEnum::Suspend : PlatformAuditActionEnum::Reactivate,
            $organization,
            array_filter(['reason' => $data['reason'] ?? null]),
        );

        return successResponse(['is_suspended' => $suspended], __('api.updated_success'));
    }

    /**
     * Per-tenant entitlement overrides: gated feature switches and seat/branch ceilings.
     */
    public function updateEntitlements(Request $request, Organization $organization): JsonResponse
    {
        $this->requirePlatformPermission(PlatformPermissionEnum::ManageTenants);

        $data = $request->validate([
            'feature_overrides' => ['nullable', 'array'],
            'max_branches_override' => ['nullable', 'integer', 'min:1'],
            'max_users_override' => ['nullable', 'integer', 'min:1'],
        ]);

        $updates = [];

        if ($request->has('feature_overrides')) {
            $updates['feature_overrides'] = $this->sanitizeOverrides($data['feature_overrides'] ?? []);
        }

        if ($request->has('max_branches_override')) {
            $updates['max_branches_override'] = $data['max_branches_override'];
        }

        if ($request->has('max_users_override')) {
            $updates['max_users_override'] = $data['max_users_override'];
        }

        $organization->forceFill($updates)->save();

        $this->audit->log($this->admin(), PlatformAuditActionEnum::UpdateEntitlements, $organization, $updates);

        return successResponse($this->entitlements->snapshot($organization->refresh()), __('api.updated_success'));
    }

    /**
     * Keep only known gated feature keys and coerce their values to booleans.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, bool>
     */
    private function sanitizeOverrides(array $overrides): array
    {
        $gated = Feature::query()->where('is_core', false)->pluck('key')->all();
        $clean = [];

        foreach ($overrides as $key => $value) {
            if (in_array($key, $gated, true)) {
                $clean[$key] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
            }
        }

        return $clean;
    }

    /**
     * @param  array<int, int>  $orgIds
     * @return array<int, array{orders: int, revenue: float}>
     */
    private function orderStatsFor(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }

        return Order::query()
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereIn('branches.organization_id', $orgIds)
            ->where('orders.status', '!=', OrderStatusEnum::Cancelled->value)
            ->groupBy('branches.organization_id')
            ->selectRaw('branches.organization_id as org_id, COUNT(*) as orders, COALESCE(SUM(orders.grand_total),0) as revenue')
            ->get()
            ->keyBy('org_id')
            ->map(fn ($row) => ['orders' => (int) $row->orders, 'revenue' => (float) $row->revenue])
            ->all();
    }

    /**
     * @param  array<int, int>  $orgIds
     * @return array<int, int>
     */
    private function userCountsFor(array $orgIds): array
    {
        if ($orgIds === []) {
            return [];
        }

        return DB::table('user_branches')
            ->join('branches', 'branches.id', '=', 'user_branches.branch_id')
            ->whereIn('branches.organization_id', $orgIds)
            ->groupBy('branches.organization_id')
            ->selectRaw('branches.organization_id as org_id, COUNT(DISTINCT user_branches.user_id) as users')
            ->pluck('users', 'org_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }
}

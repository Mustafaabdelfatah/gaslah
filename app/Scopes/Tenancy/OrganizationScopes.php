<?php

namespace App\Scopes\Tenancy;

use App\Enum\Orders\OrderStatusEnum;
use App\Models\Organization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

trait OrganizationScopes
{
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_suspended', false)->whereNull('archived_at');
    }

    public function scopeSuspended(Builder $query): Builder
    {
        return $query->where('is_suspended', true);
    }

    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function scopeNotArchived(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * Exclude the reserved platform-books organization from any tenant-facing list or
     * count. Not a global scope — a lookup by id still returns it, since the platform's
     * own accounting needs it.
     */
    public function scopeTenantsOnly(Builder $query): Builder
    {
        $reservedId = Organization::reservedBooksOrgId();

        return $reservedId === null ? $query : $query->whereKeyNot($reservedId);
    }

    /**
     * Attach the figures the platform tenant directory shows: branch and seat counts,
     * order volume and revenue.
     *
     * Everything is a correlated subquery, so a page of tenants costs one query instead of
     * a grouped query per metric merged in PHP. Cancelled orders count for neither volume
     * nor revenue; seats are distinct users across the tenant's branches.
     */
    public function scopeWithTenantStats(Builder $query): Builder
    {
        $live = fn (Builder $orders) => $orders->where('status', '!=', OrderStatusEnum::Cancelled->value);

        return $query
            ->withCount(['branches', 'orders as orders_count' => $live])
            ->withSum(['orders as revenue' => $live], 'grand_total')
            ->addSelect(['users_count' => DB::table('user_branches')
                ->join('branches', 'branches.id', '=', 'user_branches.branch_id')
                ->whereColumn('branches.organization_id', 'organizations.id')
                ->selectRaw('COUNT(DISTINCT user_branches.user_id)'),
            ]);
    }
}

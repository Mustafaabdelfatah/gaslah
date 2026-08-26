<?php

namespace App\Scopes\User;

use Illuminate\Database\Eloquent\Builder;

trait UserScopes
{
    public function scopeRelated(Builder $builder): void
    {
        $builder->when(! auth()->user()->can('view-all-user'), function ($subQuery) {
            $subQuery->where('created_by', auth()->id());
        });
    }

    public function scopeExcludeLoggedInUser(Builder $query): Builder
    {
        return $query->where('id', '!=', auth()->id());
    }

    public function scopeExcludeRoot(Builder $query): Builder
    {
        return $query->whereHas('roles', function ($q) {
            $q->where('name', '!=', 'root');
        });
    }

    public function scopeWithRole(Builder $query, ?string $role = null): Builder
    {
        return $query->when($role, function ($subQuery) use ($role) {
            $subQuery->whereHas('roles', function ($q) use ($role) {
                $q->where('name', $role);
            });
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Members of an organization, reached through their branch memberships.
     */
    public function scopeInOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->whereHas(
            'branches',
            fn (Builder $branch) => $branch->where('branches.organization_id', $organizationId)
        );
    }

    public function scopePlatformAdmins(Builder $query): Builder
    {
        return $query->where('is_platform_owner', true);
    }
}

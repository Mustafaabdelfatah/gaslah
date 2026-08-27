<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Free-text search across a tenant's name and slug, plus the operator's status switches
 * (suspended / archived).
 */
class TenantFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $this->applySearch($query)
            ->applyStatus($query);

        return $query;
    }

    private function applySearch(Builder $query): static
    {
        $search = request('search');

        when($search, static fn () => $query->where(
            fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('slug', 'like', "%{$search}%")
        ));

        return $this;
    }

    private function applyStatus(Builder $query): static
    {
        $query
            ->when(request()->has('is_suspended'), fn (Builder $q) => $q->where('is_suspended', (bool) request('is_suspended')))
            ->when(request()->has('is_archived'), fn (Builder $q) => (bool) request('is_archived')
                ? $q->whereNotNull('archived_at')
                : $q->whereNull('archived_at'));

        return $this;
    }
}

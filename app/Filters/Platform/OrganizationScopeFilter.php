<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrow a platform-console listing to one tenant.
 */
class OrganizationScopeFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query->when(
            request()->filled('organization_id'),
            fn (Builder $q) => $q->where('organization_id', request()->integer('organization_id')),
        );

        return $query;
    }
}

<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Narrow a listing to one status value (invoice draft/issued, subscription state, …).
 */
class StatusFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query->when(
            request()->filled('status'),
            fn (Builder $q) => $q->where('status', request()->string('status')->toString()),
        );

        return $query;
    }
}

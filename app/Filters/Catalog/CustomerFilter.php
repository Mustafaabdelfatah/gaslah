<?php

namespace App\Filters\Catalog;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer listing filters: the name or number being looked for, and the customer type.
 */
class CustomerFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');

        when($search, static fn () => $query->where(fn (Builder $q) => $q
            ->where('name', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%")
            ->orWhere('email', 'like', "%{$search}%")));

        $query->when(request()->filled('type'), fn (Builder $q) => $q->where('type', request('type')));

        return $query;
    }
}

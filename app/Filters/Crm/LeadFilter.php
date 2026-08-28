<?php

namespace App\Filters\Crm;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Pipeline board filters.
 */
class LeadFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');

        $query->when($search, static fn (Builder $q) => $q->where(static fn (Builder $inner) => $inner
            ->where('business_name', 'like', "%{$search}%")
            ->orWhere('contact_name', 'like', "%{$search}%")
            ->orWhere('phone', 'like', "%{$search}%")));

        $query->when(request()->filled('stage'), static fn (Builder $q) => $q->where('stage', request('stage')));
        $query->when(request()->filled('owner_id'), static fn (Builder $q) => $q->where('owner_id', request('owner_id')));
        $query->when(request()->filled('city'), static fn (Builder $q) => $q->where('city', request('city')));
        $query->when(request()->filled('source'), static fn (Builder $q) => $q->where('source', request('source')));

        // The working board: everything still being chased.
        $query->when(request()->boolean('open'), static fn (Builder $q) => $q->open());

        return $query;
    }
}

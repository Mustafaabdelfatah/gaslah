<?php

namespace App\Filters\Support;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Support inbox filters, used on both sides of the conversation.
 */
class SupportTicketFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');

        $query->when($search, static fn (Builder $q) => $q->where('subject', 'like', "%{$search}%"));

        $query->when(request()->filled('status'), static fn (Builder $q) => $q->where('status', request('status')));
        $query->when(request()->filled('priority'), static fn (Builder $q) => $q->where('priority', request('priority')));
        $query->when(request()->filled('category'), static fn (Builder $q) => $q->where('category', request('category')));
        $query->when(request()->filled('assigned_to_id'), static fn (Builder $q) => $q->where('assigned_to_id', request('assigned_to_id')));

        // The operator's working view: everything still needing someone, whichever side.
        $query->when(request()->boolean('live'), static fn (Builder $q) => $q->live());

        return $query;
    }
}

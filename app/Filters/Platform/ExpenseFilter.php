<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Platform expense filters: category, the partner who fronted it, whether it is still
 * owed back, and a date window.
 */
class ExpenseFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query
            ->when(request()->filled('category'), fn (Builder $q) => $q->where('category', request('category')))
            ->when(request()->filled('partner_id'), fn (Builder $q) => $q->where('paid_by_partner_id', request()->integer('partner_id')))
            ->when(request()->boolean('outstanding'), fn (Builder $q) => $q->outstanding())
            ->when(request()->filled('from'), fn (Builder $q) => $q->whereDate('date', '>=', request('from')))
            ->when(request()->filled('to'), fn (Builder $q) => $q->whereDate('date', '<=', request('to')));

        return $query;
    }
}

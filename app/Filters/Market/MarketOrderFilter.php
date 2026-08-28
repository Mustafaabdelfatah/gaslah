<?php

namespace App\Filters\Market;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Market order listings, on either side of the sale: where the order stands, how it is
 * being paid for, and when it was placed.
 */
class MarketOrderFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query->when(request()->filled('status'), static fn (Builder $q) => $q->where('status', request('status')));
        $query->when(request()->filled('payment_status'), static fn (Builder $q) => $q->where('payment_status', request('payment_status')));
        $query->when(request()->filled('supplier_id'), static fn (Builder $q) => $q->where('supplier_id', request('supplier_id')));

        $query->when(request()->filled('from'), static fn (Builder $q) => $q->whereDate('created_at', '>=', request('from')));
        $query->when(request()->filled('to'), static fn (Builder $q) => $q->whereDate('created_at', '<=', request('to')));

        return $query;
    }
}

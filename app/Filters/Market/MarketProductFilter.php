<?php

namespace App\Filters\Market;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Browsing the market: what kind of supply it is, who sells it, and a name search.
 */
class MarketProductFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');

        $query->when($search, static fn (Builder $q) => $q->where(static fn (Builder $inner) => $inner
            ->where('name', 'like', "%{$search}%")
            ->orWhere('name_en', 'like', "%{$search}%")));

        $query->when(request()->filled('category'), static fn (Builder $q) => $q->where('category', request('category')));
        $query->when(request()->filled('supplier_id'), static fn (Builder $q) => $q->where('supplier_id', request('supplier_id')));

        // Price bounds, each usable on its own.
        $query->when(request()->filled('min_price'), static fn (Builder $q) => $q->where('price', '>=', request('min_price')));
        $query->when(request()->filled('max_price'), static fn (Builder $q) => $q->where('price', '<=', request('max_price')));

        return $query;
    }
}

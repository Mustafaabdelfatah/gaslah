<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Coupon listing filters: code search, type, and whether the coupon is still live
 * (active, unexpired, and under its redemption cap).
 */
class CouponFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');
        when($search, static fn () => $query->where('code', 'like', '%'.mb_strtoupper($search).'%'));

        $query
            ->when(request()->filled('type'), fn (Builder $q) => $q->where('type', request('type')))
            ->when(request()->has('is_redeemable'), fn (Builder $q) => (bool) request('is_redeemable')
                ? $q->redeemable()
                : $q->whereNot(fn (Builder $inner) => $inner->redeemable()));

        return $query;
    }
}

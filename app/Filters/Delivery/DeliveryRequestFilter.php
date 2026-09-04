<?php

namespace App\Filters\Delivery;

use App\Support\BusinessDateRange;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Delivery job listing filters: direction, workflow status, assigned driver, and the
 * customer or address being looked for.
 */
class DeliveryRequestFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = request('search');

        when($search, static fn () => $query->where(fn (Builder $q) => $q
            ->where('delivery_requests.address', 'like', "%{$search}%")
            ->orWhereHas('customer', fn (Builder $customer) => $customer
                ->where('customers.name', 'like', "%{$search}%")
                ->orWhere('customers.phone', 'like', "%{$search}%"))));

        $query
            ->when(request()->filled('type'), fn (Builder $q) => $q->where('delivery_requests.type', request('type')))
            ->when(request()->filled('status'), fn (Builder $q) => $q->whereIn('delivery_requests.status', resolveArray(request('status'))))
            ->when(request()->filled('driver_id'), fn (Builder $q) => $q->where('delivery_requests.driver_id', request()->integer('driver_id')))
            ->when(request()->filled('from'), fn (Builder $q) => $q->where('delivery_requests.created_at', '>=', BusinessDateRange::startUtc(request('from'))))
            ->when(request()->filled('to'), fn (Builder $q) => $q->where('delivery_requests.created_at', '<', BusinessDateRange::endExclusiveUtc(request('to'))));

        return $query;
    }
}

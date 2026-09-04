<?php

namespace App\Filters\Orders;

use App\Support\BusinessDateRange;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Order listing filters: workflow status, payment status, the customer or ticket being
 * looked for, and a date window.
 *
 * Columns are qualified because a caller may have joined the customers table to search
 * by name.
 */
class OrderFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $this->applySearch($query)
            ->applyStatus($query)
            ->applyDateRange($query);

        return $query;
    }

    private function applySearch(Builder $query): static
    {
        $search = request('search');

        when($search, static fn () => $query->where(fn (Builder $q) => $q
            ->where('orders.order_no', 'like', "%{$search}%")
            ->orWhere('orders.barcode', 'like', "%{$search}%")
            ->orWhereHas('customer', fn (Builder $customer) => $customer
                ->where('customers.name', 'like', "%{$search}%")
                ->orWhere('customers.phone', 'like', "%{$search}%"))));

        return $this;
    }

    private function applyStatus(Builder $query): static
    {
        $query
            ->when(request()->filled('status'), fn (Builder $q) => $q->whereIn('orders.status', resolveArray(request('status'))))
            ->when(request()->filled('payment_status'), fn (Builder $q) => $q->whereIn('orders.payment_status', resolveArray(request('payment_status'))))
            ->when(request('outstanding') === '1', fn (Builder $q) => $q->outstanding())
            ->when(request('delivery') === '1', fn (Builder $q) => $q->whereHas('deliveryRequests'))
            ->when(request('late') === '1', fn (Builder $q) => $q
                ->whereNotNull('orders.due_at')
                ->where('orders.due_at', '<', now())
                ->whereNotIn('orders.status', ['delivered', 'cancelled']))
            ->when(request()->filled('priority'), fn (Builder $q) => $q->where('orders.priority', request('priority')))
            ->when(request()->filled('customer_id'), fn (Builder $q) => $q->where('orders.customer_id', request()->integer('customer_id')));

        return $this;
    }

    private function applyDateRange(Builder $query): static
    {
        if (request()->filled('from')) {
            $query->where('orders.created_at', '>=', BusinessDateRange::startUtc(request('from')));
        }

        if (request()->filled('to')) {
            $query->where('orders.created_at', '<', BusinessDateRange::endExclusiveUtc(request('to')));
        }

        return $this;
    }
}

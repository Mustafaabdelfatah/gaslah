<?php

namespace App\Filters\Platform;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Payout batch listing filters: settlement status, owning tenant, and whether the batch
 * is still open.
 */
class PayoutFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query
            ->when(request()->filled('status'), fn (Builder $q) => $q->where('status', request('status')))
            ->when(
                request()->filled('organization_id') || request()->filled('org_id'),
                fn (Builder $q) => $q->where('organization_id', (int) request('organization_id', request('org_id'))),
            )
            ->when(request()->boolean('is_open'), fn (Builder $q) => $q->open());

        return $query;
    }
}

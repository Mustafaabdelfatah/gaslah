<?php

namespace App\Filters\Global;

use App\Support\BusinessDateRange;
use Closure;

class DateFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        if (! empty(request('start'))) {
            $query->where('created_at', '>=', BusinessDateRange::startUtc(request('start')));
        }

        if (! empty(request('end'))) {
            $query->where('created_at', '<', BusinessDateRange::endExclusiveUtc(request('end')));
        }

        return $query;
    }
}

<?php

namespace App\Filters\Audit;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Audit trail filters: which kind of record, what happened to it, and when.
 *
 * Dates are parsed defensively — a malformed value from a URL is ignored rather than
 * throwing, since an audit screen should degrade to "unfiltered" and never to a 500.
 */
class AuditFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $query
            ->when(request()->filled('entity'), fn (Builder $q) => $q->where('log_name', request('entity')))
            ->when(request()->filled('action'), fn (Builder $q) => $q->where('event', request('action')))
            ->when(request()->filled('causer_id'), fn (Builder $q) => $q->where('causer_id', request()->integer('causer_id')));

        $from = $this->date(request('from'));
        $to = $this->date(request('to'));

        when($from, static fn () => $query->where('created_at', '>=', $from->startOfDay()));
        when($to, static fn () => $query->where('created_at', '<=', $to->endOfDay()));

        return $query;
    }

    private function date(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}

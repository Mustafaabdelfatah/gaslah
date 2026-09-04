<?php

namespace App\Filters\Catalog;

use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Customer listing filters: the name or number being looked for, and the customer type.
 */
class CustomerFilter
{
    public function handle($request, Closure $next)
    {
        $query = $next($request);

        $search = trim((string) request('search', ''));

        $query->when($search !== '', static function (Builder $query) use ($search): void {
            $phoneSearch = preg_replace('/\D+/', '', $search);

            $query->where(static function (Builder $query) use ($search, $phoneSearch): void {
                $query
                    ->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");

                if ($phoneSearch !== '') {
                    // Till searches normally start with a phone prefix. Avoid a leading
                    // wildcard so the organization/phone index remains usable at scale.
                    $query->orWhere('phone', 'like', "{$phoneSearch}%");
                }
            });
        });

        $query->when(request()->filled('type'), fn (Builder $q) => $q->where('type', request('type')));

        return $query;
    }
}

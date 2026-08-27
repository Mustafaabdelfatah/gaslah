<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses self-service signup while the operator has it closed.
 *
 * Being middleware is the point: it runs before the form request validates, so a closed
 * signup cannot be used to probe which email addresses exist through the unique rule.
 */
class EnsurePublicSignupIsOpen
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(
            (bool) config('services.platform.allow_public_signup', true),
            Response::HTTP_FORBIDDEN,
            __('api.signup_closed'),
        );

        return $next($request);
    }
}

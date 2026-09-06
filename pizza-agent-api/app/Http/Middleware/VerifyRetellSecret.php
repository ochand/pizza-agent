<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyRetellSecret
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! hash_equals((string) config('services.retell.api_secret'), (string) $request->header('X-Api-Key'))) {
            abort(401, 'Invalid API key.');
        }

        return $next($request);
    }
}

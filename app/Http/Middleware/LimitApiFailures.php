<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps people from guessing API tokens. Laravel checks the token before its own rate
 * limiter, so a wrong token would never be counted there. This runs first: an address
 * that sent 10 calls with a wrong or missing token in a minute is refused for the rest
 * of it, without looking at what it sends. Calls with a good token are never counted.
 */
class LimitApiFailures
{
    private const MAX_FAILURES = 10;

    public function handle(Request $request, Closure $next): Response
    {
        $key = 'api-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::MAX_FAILURES)) {
            return response()->json(['error' => 'Too many failed attempts. Try again in a minute.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }

        $response = $next($request);

        if ($response->getStatusCode() === 401) {
            RateLimiter::hit($key, 60);
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\I18n\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sets the language of the request: a signed-in person's own choice; for pages before sign-in
 * (login, first setup) the language picked there (kept in the session) or else the browser's.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null) {
            $locale = Locales::resolve($user->locale);
        } else {
            $picked = $request->query('lang');

            if (is_string($picked) && Locales::isAvailable($picked) && $request->hasSession()) {
                $request->session()->put('locale', $picked);
            }

            $stored = $request->hasSession() ? $request->session()->get('locale') : null;
            $locale = Locales::resolve(is_string($stored) ? $stored : Locales::fromRequest($request));
        }

        app()->setLocale($locale);

        return $next($request);
    }
}

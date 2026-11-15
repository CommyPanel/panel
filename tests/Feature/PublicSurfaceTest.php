<?php

use Illuminate\Routing\Route as RouteDefinition;
use Illuminate\Support\Facades\Route;

/**
 * Everything on this server that answers without a signed-in person or a token. It is a short list on
 * purpose; a new entry here is a decision (and needs its own protection: a signature, a one-time token,
 * a rate limit), never an accident.
 */
it('has only these routes that work without signing in or a token', function () {
    $open = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        /** @var RouteDefinition $route */
        $middleware = collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m));

        // Developer tools of the dev-only packages (Boost, Inertia devtools): not in a release (no dev
        // dependencies) and local-only in the app; production is checked below by the missing packages.
        if (str_starts_with($route->uri(), '_boost') || str_starts_with($route->uri(), '_inertia/devtools')) {
            continue;
        }

        if ($route->isFallback || $middleware->contains(fn ($m) => $m === 'auth' || str_starts_with($m, 'auth:'))) {
            continue;
        }

        // The home address redirects for any method; it does nothing else, so it counts once.
        $methods = $route->getName() === 'home' ? ['GET'] : array_diff($route->methods(), ['HEAD', 'OPTIONS', 'QUERY']);

        foreach ($methods as $method) {
            $open[] = $method.' /'.ltrim($route->uri(), '/');
        }
    }

    sort($open);

    expect($open)->toBe([
        'GET /',             // redirects to the dashboard, which asks for a login
        'GET /login',
        'GET /setup/{token}',   // the one-time first-run link
        'GET /two-factor-challenge',
        'GET /up',           // health check, says nothing
        'POST /login',       // rate limited
        'POST /setup/{token}',
        'POST /two-factor-challenge', // rate limited
        'POST /webhooks/deploy/{hook}', // signed by the Git host, rate limited
        'POST /webhooks/github',        // signed by GitHub, rate limited
    ]);
});

it('does not offer the framework routes the panel never uses', function () {
    $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($route) => $route->uri());

    expect($uris->contains('sanctum/csrf-cookie'))->toBeFalse()
        ->and($uris->contains(fn ($uri) => str_starts_with($uri, 'storage/')))->toBeFalse();
});

it('keeps the developer tools out of a release', function () {
    $composer = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true);

    expect($composer['require'])->not->toHaveKey('laravel/boost')
        ->and($composer['require-dev'])->toHaveKey('laravel/boost');
});

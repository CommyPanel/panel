<?php

use App\Exceptions\PanelNotMigrated;
use App\Http\Controllers\PushWebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\LimitApiFailures;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;
use Laravel\Sanctum\Http\Middleware\CheckForAnyAbility;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            // Git hosts call this without a session, so it is outside the web group.
            Route::post('webhooks/deploy/{hook}', PushWebhookController::class)
                ->middleware('throttle:webhooks')
                ->name('webhooks.deploy');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The agent's nginx site for the panel's own domain proxies from loopback.
        $middleware->trustProxies(at: ['127.0.0.1', '::1']);
        // Laravel runs authentication before almost anything else, so a wrong API token would be
        // refused before it could be counted. This one goes first (see LimitApiFailures).
        $middleware->prependToPriorityList(before: AuthenticatesRequests::class, prepend: LimitApiFailures::class);
        $middleware->alias([
            'abilities' => CheckAbilities::class,
            'ability' => CheckForAnyAbility::class,
        ]);
        // No Link preload headers: the page already preloads its scripts with
        // <link> tags, and a page with many chunks (an app's detail page) would
        // send a header block bigger than nginx's default 4k FastCGI buffer,
        // which nginx reports as a 502 on a full reload.
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->map(fn (SQLiteDatabaseDoesNotExistException $e) => new PanelNotMigrated($e));
        $exceptions->map(fn (QueryException $e) => $e->getPrevious() instanceof SQLiteDatabaseDoesNotExistException
            ? new PanelNotMigrated($e)
            : $e);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();

<?php

use App\Exceptions\PanelNotMigrated;
use App\Http\Controllers\PushWebhookController;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Database\QueryException;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
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
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
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

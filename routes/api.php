<?php

use App\Auth\Permissions;
use App\Http\Controllers\Api\V1\AppsController;
use App\Http\Controllers\Api\V1\ControlController;
use App\Http\Controllers\Api\V1\DeploymentsController;
use App\Http\Controllers\Api\V1\EnvController;
use App\Http\Controllers\Api\V1\LogsController;
use App\Http\Controllers\Api\V1\TokenController;
use App\Http\Controllers\FallbackController;
use App\Http\Middleware\LimitApiFailures;
use Illuminate\Support\Facades\Route;

// The token API (see docs/api.md). Bearer tokens only: nothing here accepts a browser
// session. Failed attempts are counted first (LimitApiFailures), so guessing a token is
// slow from the first try; a token also needs the ability for the call AND the permission
// of its owner, checked on every request.
Route::prefix('v1')->name('api.')->middleware([LimitApiFailures::class, 'throttle:api', 'auth:sanctum'])->group(function () {
    // What this token is: no ability needed beyond being a valid one.
    Route::get('token', [TokenController::class, 'show'])->name('token.show');

    Route::middleware(['abilities:apps:read', 'can:'.Permissions::APPS_VIEW])->group(function () {
        Route::get('apps', [AppsController::class, 'index'])->name('apps.index');
        Route::get('apps/{app:slug}', [AppsController::class, 'show'])->name('apps.show');
        Route::get('apps/{app:slug}/deployments', [DeploymentsController::class, 'index'])->name('apps.deployments.index');
        Route::get('apps/{app:slug}/deployments/{number}', [DeploymentsController::class, 'show'])->whereNumber('number')->name('apps.deployments.show');
    });

    Route::middleware(['abilities:apps:deploy', 'can:'.Permissions::APPS_DEPLOY])->group(function () {
        Route::post('apps/{app:slug}/deployments', [DeploymentsController::class, 'store'])->name('apps.deployments.store');
        Route::post('apps/{app:slug}/apply', [DeploymentsController::class, 'apply'])->name('apps.apply');
    });

    Route::middleware(['abilities:apps:control', 'can:'.Permissions::APPS_CONTROL])->group(function () {
        Route::post('apps/{app:slug}/start', [ControlController::class, 'start'])->name('apps.start');
        Route::post('apps/{app:slug}/stop', [ControlController::class, 'stop'])->name('apps.stop');
        Route::post('apps/{app:slug}/restart', [ControlController::class, 'restart'])->name('apps.restart');
    });

    Route::get('apps/{app:slug}/logs', [LogsController::class, 'show'])
        ->middleware(['abilities:apps:logs', 'can:'.Permissions::APPS_LOGS])
        ->name('apps.logs');

    // Variables are write-only here: names can be listed, values are never returned.
    Route::middleware(['abilities:apps:env', 'can:'.Permissions::ENV_MANAGE])->group(function () {
        Route::get('apps/{app:slug}/env', [EnvController::class, 'index'])->name('apps.env.index');
        Route::put('apps/{app:slug}/env/{key}', [EnvController::class, 'update'])->where('key', '[A-Za-z_][A-Za-z0-9_]*')->name('apps.env.update');
        Route::delete('apps/{app:slug}/env/{key}', [EnvController::class, 'destroy'])->where('key', '[A-Za-z_][A-Za-z0-9_]*')->name('apps.env.destroy');
    });
});

// Anything under /api that matches nothing: a plain JSON 404 (or 405 with the allowed methods), with no
// session and no cookie, whatever the method (the web group's fallback would ask a bot for a CSRF token).
Route::match(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE'], '{fallbackPlaceholder}', FallbackController::class)
    ->where('fallbackPlaceholder', '.*')->fallback();

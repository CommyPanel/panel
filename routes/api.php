<?php

use App\Auth\Permissions;
use App\Http\Controllers\Api\V1\AppsController;
use App\Http\Controllers\Api\V1\DeploymentsController;
use App\Http\Middleware\LimitApiFailures;
use Illuminate\Support\Facades\Route;

// The token API (see docs/api.md). Bearer tokens only: nothing here accepts a browser
// session. Failed attempts are counted first (LimitApiFailures), so guessing a token is
// slow from the first try; a token also needs the ability for the call AND the permission
// of its owner, checked on every request.
Route::prefix('v1')->name('api.')->middleware([LimitApiFailures::class, 'throttle:api', 'auth:sanctum'])->group(function () {
    Route::middleware(['abilities:apps:read', 'can:'.Permissions::APPS_VIEW])->group(function () {
        Route::get('apps', [AppsController::class, 'index'])->name('apps.index');
        Route::get('apps/{app:slug}', [AppsController::class, 'show'])->name('apps.show');
        Route::get('apps/{app:slug}/deployments/{number}', [DeploymentsController::class, 'show'])->whereNumber('number')->name('apps.deployments.show');
    });

    Route::post('apps/{app:slug}/deployments', [DeploymentsController::class, 'store'])
        ->middleware(['abilities:apps:deploy', 'can:'.Permissions::APPS_DEPLOY])
        ->name('apps.deployments.store');
});

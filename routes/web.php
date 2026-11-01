<?php

use App\Auth\Permissions;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppDomainController;
use App\Http\Controllers\AppSetupController;
use App\Http\Controllers\AppSourceController;
use App\Http\Controllers\AppStateController;
use App\Http\Controllers\AppWebhookController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DeployKeyController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EnvVarController;
use App\Http\Controllers\GitTokenController;
use App\Http\Controllers\HttpsController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServerAddressController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerDockerController;
use App\Http\Controllers\ServerNginxController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WelcomeController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard')->name('home');

Route::middleware(['guest', 'throttle:10,1'])->group(function () {
    Route::get('/setup/{token}', [SetupController::class, 'show'])->name('setup');
    Route::post('/setup/{token}', [SetupController::class, 'store'])->name('setup.store');
});

Route::middleware('auth')->group(function () {
    // Any signed-in user: their own dashboard and account settings.
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::inertia('/settings/appearance', 'settings/appearance')->name('settings.appearance');
    Route::inertia('/settings/security', 'settings/security')
        ->middleware('password.confirm')
        ->name('settings.security');

    // Everything below is guarded by a permission (see App\Auth\Permissions).
    // `create` must be registered before `show`, or /apps/create would match /apps/{app}.
    Route::resource('apps', AppController::class)->parameters(['apps' => 'app:slug'])
        ->only(['create', 'store'])->middleware('can:'.Permissions::APPS_CREATE);
    Route::resource('apps', AppController::class)->parameters(['apps' => 'app:slug'])
        ->only(['index', 'show'])->middleware('can:'.Permissions::APPS_VIEW);
    Route::resource('apps', AppController::class)->parameters(['apps' => 'app:slug'])
        ->only(['update'])->middleware('can:'.Permissions::APPS_UPDATE);
    Route::resource('apps', AppController::class)->parameters(['apps' => 'app:slug'])
        ->only(['destroy'])->middleware('can:'.Permissions::APPS_DELETE);

    Route::scopeBindings()->prefix('apps/{app:slug}')->name('apps.')->group(function () {
        Route::middleware('can:'.Permissions::APPS_CREATE)->group(function () {
            Route::get('setup', [AppSetupController::class, 'show'])->name('setup');
            Route::post('setup/check', [AppSetupController::class, 'check'])->name('setup.check');
            Route::post('setup/upload', [AppSetupController::class, 'upload'])->name('setup.upload');
            Route::post('setup/continue', [AppSetupController::class, 'continue'])->name('setup.continue');
            Route::post('setup/back', [AppSetupController::class, 'back'])->name('setup.back');
            Route::post('setup/settings', [AppSetupController::class, 'settings'])->name('setup.settings');
            Route::post('setup/finish', [AppSetupController::class, 'finish'])->name('setup.finish');
            Route::delete('setup', [AppSetupController::class, 'cancel'])->name('setup.cancel');
        });

        Route::get('deployments/{deployment:number}', [DeploymentController::class, 'show'])
            ->middleware('can:'.Permissions::APPS_VIEW)->name('deployments.show');

        Route::middleware('can:'.Permissions::APPS_DEPLOY)->group(function () {
            Route::post('deployments', [DeploymentController::class, 'store'])->name('deployments.store');
            Route::post('deployments/{deployment:number}/rollback', [DeploymentController::class, 'rollback'])->name('deployments.rollback');
        });

        Route::middleware('can:'.Permissions::ENV_MANAGE)->group(function () {
            Route::post('env', [EnvVarController::class, 'store'])->name('env.store');
            Route::delete('env/{key}', [EnvVarController::class, 'destroy'])->where('key', '[A-Za-z_][A-Za-z0-9_]*')->name('env.destroy');
            Route::post('env/import', [EnvVarController::class, 'import'])->name('env.import');
        });

        Route::middleware('can:'.Permissions::APPS_UPDATE)->group(function () {
            Route::post('deploy-key', [DeployKeyController::class, 'store'])->name('deploy-key.store');
            Route::post('git-token', [GitTokenController::class, 'store'])->name('git-token.store');
            Route::delete('git-token', [GitTokenController::class, 'destroy'])->name('git-token.destroy');
            Route::post('webhook', [AppWebhookController::class, 'store'])->name('webhook.store');
            Route::delete('webhook', [AppWebhookController::class, 'destroy'])->name('webhook.destroy');
            Route::post('source', [AppSourceController::class, 'upload'])->name('source.upload');
        });

        Route::middleware('can:'.Permissions::APPS_CONTROL)->group(function () {
            Route::post('stop', [AppStateController::class, 'stop'])->name('stop');
            Route::post('start', [AppStateController::class, 'start'])->name('start');
            Route::post('restart', [AppStateController::class, 'restart'])->name('restart');
        });

        Route::middleware('can:'.Permissions::APPS_DEACTIVATE)->group(function () {
            Route::post('deactivate', [AppStateController::class, 'deactivate'])->name('deactivate');
            Route::post('activate', [AppStateController::class, 'activate'])->name('activate');
        });

        Route::middleware('can:'.Permissions::DOMAINS_MANAGE)->group(function () {
            Route::post('domains', [AppDomainController::class, 'store'])->name('domains.store');
            Route::post('certificate', [CertificateController::class, 'store'])->name('certificate.store');
        });
    });

    Route::prefix('server')->name('server.')->group(function () {
        Route::middleware('can:'.Permissions::SERVER_VIEW)->group(function () {
            Route::get('/', [ServerController::class, 'index'])->name('index');
            Route::get('docker', [ServerDockerController::class, 'index'])->name('docker');
            Route::get('nginx', [ServerNginxController::class, 'index'])->name('nginx');
        });

        // Container logs can hold secrets, so reading them is a management action.
        Route::middleware('can:'.Permissions::SERVER_MANAGE)->group(function () {
            Route::get('docker/containers/{name}/logs', [ServerDockerController::class, 'logs'])
                ->where('name', '[a-z0-9][a-z0-9-]*')->name('docker.logs');
            Route::post('docker/prune', [ServerDockerController::class, 'prune'])->name('docker.prune');
            Route::post('docker/restart', [ServerDockerController::class, 'restart'])
                ->middleware('password.confirm')->name('docker.restart');
            Route::post('nginx/test', [ServerNginxController::class, 'test'])->name('nginx.test');
            Route::post('nginx/reload', [ServerNginxController::class, 'reload'])->name('nginx.reload');
        });
    });

    Route::middleware('can:'.Permissions::DOMAINS_SETTINGS)->group(function () {
        Route::get('welcome', [WelcomeController::class, 'show'])->name('welcome');
        Route::post('welcome/terms', [WelcomeController::class, 'terms'])->name('welcome.terms');
        Route::post('welcome/domain', [WelcomeController::class, 'domain'])->name('welcome.domain');
        Route::post('welcome/domain/check', [WelcomeController::class, 'check'])->name('welcome.check');
        Route::delete('welcome/domain', [WelcomeController::class, 'clear'])->name('welcome.clear');
        Route::put('settings/https', [HttpsController::class, 'update'])->name('settings.https.update');
        Route::put('settings/server-address', [ServerAddressController::class, 'update'])->name('settings.server-address.update');
        Route::post('settings/server-address/detect', [ServerAddressController::class, 'detect'])->name('settings.server-address.detect');
    });

    Route::middleware('can:'.Permissions::DOMAINS_VIEW)->group(function () {
        Route::get('domains', [DomainController::class, 'index'])->name('domains.index');
        Route::get('domains/{domain}', [DomainController::class, 'show'])->name('domains.show');
    });
    Route::middleware('can:'.Permissions::DOMAINS_MANAGE)->group(function () {
        Route::post('domains/{domain}/check', [DomainController::class, 'check'])->name('domains.check');
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->name('domains.destroy');
    });

    Route::get('/settings/audit-log', [AuditLogController::class, 'index'])
        ->middleware('can:'.Permissions::AUDIT_VIEW)->name('settings.audit-log');

    Route::get('users', [UserController::class, 'index'])->middleware('can:'.Permissions::USERS_VIEW)->name('users.index');
    Route::middleware('can:'.Permissions::USERS_CREATE)->group(function () {
        Route::get('users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('users', [UserController::class, 'store'])->name('users.store');
    });
    Route::middleware('can:'.Permissions::USERS_UPDATE)->group(function () {
        Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    });
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('can:'.Permissions::USERS_DELETE)->name('users.destroy');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:'.Permissions::ROLES_VIEW)->name('roles.index');
    Route::middleware('can:'.Permissions::ROLES_MANAGE)->group(function () {
        Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
        Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
        Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
        Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
        Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');
    });

    Route::get('permissions', [PermissionController::class, 'index'])->middleware('can:'.Permissions::PERMISSIONS_VIEW)->name('permissions.index');
});

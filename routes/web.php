<?php

use App\Auth\Permissions;
use App\Http\Controllers\AppContainerController;
use App\Http\Controllers\AppController;
use App\Http\Controllers\AppDomainController;
use App\Http\Controllers\AppLinkController;
use App\Http\Controllers\AppLockController;
use App\Http\Controllers\AppLogController;
use App\Http\Controllers\AppNginxController;
use App\Http\Controllers\AppSetupController;
use App\Http\Controllers\AppSourceController;
use App\Http\Controllers\AppStateController;
use App\Http\Controllers\AppTerminalController;
use App\Http\Controllers\AppUsageController;
use App\Http\Controllers\AppVolumeController;
use App\Http\Controllers\AppVolumeFilesController;
use App\Http\Controllers\AppWebhookController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\CertificateController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DatabaseServerController;
use App\Http\Controllers\DeployKeyController;
use App\Http\Controllers\DeploymentController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\EnvVarController;
use App\Http\Controllers\GitTokenController;
use App\Http\Controllers\HttpsController;
use App\Http\Controllers\ManagedDatabaseController;
use App\Http\Controllers\PermissionController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\ServerAddressController;
use App\Http\Controllers\ServerController;
use App\Http\Controllers\ServerDockerController;
use App\Http\Controllers\ServerNginxController;
use App\Http\Controllers\ServerUsageController;
use App\Http\Controllers\SetupController;
use App\Http\Controllers\UpdateController;
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

        Route::get('containers', [AppContainerController::class, 'show'])
            ->middleware('can:'.Permissions::APPS_VIEW)->name('containers');

        Route::get('usage', [AppUsageController::class, 'show'])
            ->middleware('can:'.Permissions::APPS_VIEW)->name('usage');

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
            Route::put('lock', [AppLockController::class, 'app'])->name('lock');
            Route::put('volumes/{volume}/lock', [AppLockController::class, 'volume'])->whereNumber('volume')->name('volumes.lock');
            Route::post('volumes', [AppVolumeController::class, 'store'])->name('volumes.store');
            Route::delete('volumes/{volume}', [AppVolumeController::class, 'destroy'])->whereNumber('volume')->name('volumes.destroy');
            Route::delete('volumes/{name}/data', [AppVolumeFilesController::class, 'destroyData'])->where('name', '[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?')->middleware('password.confirm')->name('volumes.data.destroy');
        });

        Route::get('links', [AppLinkController::class, 'index'])->middleware('can:'.Permissions::APPS_LINKS)->name('links.index');

        Route::middleware('can:'.Permissions::APPS_LINKS)->group(function () {
            Route::post('links/database', [AppLinkController::class, 'storeDatabase'])->name('links.database.store');
            Route::post('links/app', [AppLinkController::class, 'storeApp'])->name('links.app.store');
            Route::delete('links/database/{databaseLink}', [AppLinkController::class, 'destroyDatabase'])->whereNumber('databaseLink')->name('links.database.destroy');
            Route::delete('links/app/{appLink}', [AppLinkController::class, 'destroyApp'])->whereNumber('appLink')->name('links.app.destroy');
        });

        Route::middleware('can:'.Permissions::APPS_LOGS)->group(function () {
            Route::get('logs', [AppLogController::class, 'show'])->name('logs.show');
            Route::get('logs/download', [AppLogController::class, 'download'])->name('logs.download');
        });

        Route::middleware('can:'.Permissions::APPS_VOLUMES)->where(['name' => '[a-z0-9]([a-z0-9-]{0,30}[a-z0-9])?'])->group(function () {
            Route::get('volumes', [AppVolumeFilesController::class, 'index'])->name('volumes.index');
            Route::get('volumes/{name}', [AppVolumeFilesController::class, 'show'])->name('volumes.show');
            Route::get('volumes/{name}/file', [AppVolumeFilesController::class, 'file'])->name('volumes.file');
            Route::get('volumes/{name}/download', [AppVolumeFilesController::class, 'download'])->name('volumes.download');
        });

        Route::post('terminal', [AppTerminalController::class, 'ticket'])
            ->middleware(['can:'.Permissions::APPS_TERMINAL, 'password.confirm', 'throttle:10,1'])->name('terminal');

        Route::middleware('can:'.Permissions::APPS_NGINX)->group(function () {
            Route::get('nginx', [AppNginxController::class, 'show'])->name('nginx.show');
            Route::put('nginx', [AppNginxController::class, 'update'])->middleware('password.confirm')->name('nginx.update');
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

    Route::prefix('databases')->name('databases.')->scopeBindings()->group(function () {
        Route::middleware('can:'.Permissions::DATABASES_VIEW)->group(function () {
            Route::get('/', [DatabaseServerController::class, 'index'])->name('index');
            Route::get('{server:slug}', [DatabaseServerController::class, 'show'])->name('show');
        });

        Route::middleware('can:'.Permissions::DATABASES_MANAGE)->group(function () {
            Route::post('/', [DatabaseServerController::class, 'store'])->name('store');
            Route::post('{server:slug}/start', [DatabaseServerController::class, 'start'])->name('start');
            Route::post('{server:slug}/stop', [DatabaseServerController::class, 'stop'])->name('stop');
            Route::post('{server:slug}/restart', [DatabaseServerController::class, 'restart'])->name('restart');
            Route::post('{server:slug}/retry', [DatabaseServerController::class, 'retry'])->name('retry');
            Route::delete('{server:slug}', [DatabaseServerController::class, 'destroy'])->middleware('password.confirm')->name('destroy');
            Route::post('{server:slug}/databases', [ManagedDatabaseController::class, 'store'])->name('databases.store');
            Route::delete('{server:slug}/databases/{database}', [ManagedDatabaseController::class, 'destroy'])->whereNumber('database')->middleware('password.confirm')->name('databases.destroy');
            Route::post('{server:slug}/databases/{database}/password', [ManagedDatabaseController::class, 'password'])->whereNumber('database')->middleware('password.confirm')->name('databases.password');
        });

        Route::get('{server:slug}/databases/{database}/credentials', [ManagedDatabaseController::class, 'credentials'])
            ->whereNumber('database')->middleware(['can:'.Permissions::DATABASES_CREDENTIALS, 'password.confirm'])->name('databases.credentials');
    });

    Route::prefix('server')->name('server.')->group(function () {
        Route::middleware('can:'.Permissions::SERVER_VIEW)->group(function () {
            Route::get('/', [ServerController::class, 'index'])->name('index');
            Route::get('usage', [ServerUsageController::class, 'show'])->name('usage');
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

    // Anyone who can see the server sees whether it is up to date; checking by hand and
    // switching the automatic check on or off is server management.
    Route::get('/settings/updates', [UpdateController::class, 'show'])
        ->middleware('can:'.Permissions::SERVER_VIEW)->name('settings.updates');
    Route::middleware('can:'.Permissions::SERVER_MANAGE)->group(function () {
        Route::post('/settings/updates/check', [UpdateController::class, 'check'])->name('settings.updates.check');
        Route::put('/settings/updates', [UpdateController::class, 'update'])->name('settings.updates.update');
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

<?php

use App\Audit\Severity;
use App\Databases\AppLinks;
use App\Databases\DatabaseBackups;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Databases\RestoreDatabase;
use App\Enums\DatabaseEngine;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/restoreover-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dir, 'commypanel.backups.keep_databases' => 1]);
    Sleep::fake();

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
    $this->shop = app(ManageDatabases::class)->create($this->server, 'shop');
    $this->backup = app(DatabaseBackups::class)->create($this->shop)['name'];
    $this->url = "/databases/main/backups/{$this->backup}/restore-over";
    $this->replaces = fn () => collect($this->host->actions())->where('action', 'restoreDatabase')->values();
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('takes a safety dump, replaces the database and leaves nothing behind', function () {
    app(RestoreDatabase::class)->startOver($this->server, $this->shop, $this->backup);

    $dumps = collect(app(DatabaseBackups::class)->all($this->server))->pluck('name');
    $calls = ($this->replaces)();

    // The safety dump did not push the dump being loaded out, though only one is kept.
    expect($dumps)->toHaveCount(2)->and($dumps)->toContain($this->backup)
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['args'])->toBe(['container' => 'db-main', 'database' => 'shop', 'user' => 'shop', 'server' => 'main', 'file' => $this->backup, 'mode' => 'replace'])
        ->and($this->host->actionNames())->toContain('dumpDatabase')
        ->and($this->shop->fresh()->only(['restore_status', 'restore_from', 'restore_error', 'restore_notice']))->each->toBeNull();
});

it('puts the safety dump back when the agent fails, and says so', function () {
    $this->host->failOn('restoreDatabase');
    $before = app(DatabaseBackups::class)->all($this->server);

    app(RestoreDatabase::class)->startOver($this->server, $this->shop, $this->backup);

    $shop = $this->shop->fresh();
    $calls = ($this->replaces)();
    $safety = collect(app(DatabaseBackups::class)->all($this->server))->pluck('name')->diff(array_column($before, 'name'))->first();

    expect($shop->restore_status)->toBeNull()
        ->and($shop->restore_notice)->toContain('put back as it was')->toContain($safety)
        ->and($calls)->toHaveCount(1)
        ->and($calls[0]['args']['file'])->toBe($safety)
        ->and(AuditLog::query()->where('action', 'database.restore_replace_failed')->first()->metadata)
        ->toMatchArray(['changed' => true, 'put_back' => true, 'safety' => $safety]);

    $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
        ->where('databases.0.restore.status', 'reverted')->etc());
});

it('marks the database failed and names the safety copy when the put-back fails too', function () {
    $this->host->failRestoresWith('Agent error: loading the dump failed, and the database is now empty: syntax error');

    app(RestoreDatabase::class)->startOver($this->server, $this->shop, $this->backup);

    $shop = $this->shop->fresh();

    expect($shop->restore_status)->toBe('failed')
        ->and($shop->restore_error)->toContain('syntax error')->toContain('putting the database back')->toContain('safety copy')
        ->and(($this->replaces)())->toHaveCount(2)
        ->and(AuditLog::query()->where('action', 'database.restore_replace_failed')->first()->metadata)->toMatchArray(['put_back' => false]);
});

it('does not touch anything when the agent refuses before changing the database', function () {
    $this->host->failRestoresWith('Agent error: this dump was made by mysql and this server runs postgres, so it is not restored');

    app(RestoreDatabase::class)->startOver($this->server, $this->shop, $this->backup);

    expect(($this->replaces)())->toHaveCount(1)
        ->and($this->shop->fresh()->restore_notice)->toContain('Nothing was changed')
        ->and($this->shop->fresh()->restore_status)->toBeNull();
});

it('stops before changing anything if the safety dump cannot be made', function () {
    $this->host->failOn('dumpDatabase');

    app(RestoreDatabase::class)->startOver($this->server, $this->shop, $this->backup);

    expect(($this->replaces)())->toHaveCount(0)
        ->and($this->shop->fresh()->restore_notice)->toContain('safety copy of the database could not be made');
});

it('refuses a stopped server, a running restore, a missing dump and another server\'s database', function () {
    $restore = app(RestoreDatabase::class);

    expect(fn () => $restore->startOver($this->server, $this->shop, 'shop-20200101-000000.sql.gz'))->toThrow(DatabaseException::class, 'no such backup');

    $other = app(DatabaseServers::class)->create('Other', DatabaseEngine::Mariadb, '11.4')->fresh();
    $foreign = app(ManageDatabases::class)->create($other, 'wiki');
    expect(fn () => $restore->startOver($this->server, $foreign, $this->backup))->toThrow(DatabaseException::class, 'not on this server');

    $this->shop->forceFill(['restore_status' => 'restoring'])->save();
    expect(fn () => $restore->startOver($this->server, $this->shop->fresh(), $this->backup))->toThrow(DatabaseException::class, 'already being loaded');
    $this->shop->forceFill(['restore_status' => null])->save();

    app(DatabaseServers::class)->stop($this->server);
    expect(fn () => $restore->startOver($this->server->fresh(), $this->shop->fresh(), $this->backup))->toThrow(DatabaseException::class, 'not running');

    expect(($this->replaces)())->toHaveCount(0);
});

describe('in the panel', function () {
    it('needs the password, the database name typed, and is recorded as dangerous', function () {
        $this->post($this->url, ['database' => 'shop', 'confirm' => 'shop'])->assertRedirect();
        expect(($this->replaces)())->toHaveCount(0);

        ($this->confirmed)()->post($this->url, ['database' => 'shop', 'confirm' => 'wrong'])->assertSessionHasErrors('database');
        expect(($this->replaces)())->toHaveCount(0);

        ($this->confirmed)()->post($this->url, ['database' => 'shop', 'confirm' => 'shop'])->assertSessionHas('status');

        $entry = AuditLog::query()->where('action', 'database.restore_replaced')->first();

        expect(($this->replaces)())->toHaveCount(1)
            ->and($entry->metadata)->toBe(['server' => 'main', 'file' => $this->backup, 'database' => 'shop'])
            ->and(json_encode($entry->metadata))->not->toContain($this->shop->password);
    });

    it('says why it cannot start', function () {
        ($this->confirmed)()->post($this->url, ['database' => 'nothing', 'confirm' => 'nothing'])->assertSessionHasErrors('database');

        app(DatabaseServers::class)->stop($this->server);
        ($this->confirmed)()->post($this->url, ['database' => 'shop', 'confirm' => 'shop'])->assertSessionHasErrors('database');
    });

    it('needs the manage permission', function () {
        $this->actingAs(User::factory()->create(['is_super' => false]));

        ($this->confirmed)()->post($this->url, ['database' => 'shop', 'confirm' => 'shop'])->assertForbidden();
    });

    it('lists the apps that use the database for the confirmation', function () {
        $app = App::factory()->create(['slug' => 'shop-app', 'name' => 'Shop app']);
        app(AppLinks::class)->linkDatabase($app, $this->shop, 'DATABASE');

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->where('databases.0.apps.0.name', 'Shop app')->etc());
    });
});

it('grades the audit entries', function () {
    expect(Severity::of('database.restore_replaced'))->toBe('danger')
        ->and(Severity::of('database.restore_replace_failed'))->toBe('warning');
});

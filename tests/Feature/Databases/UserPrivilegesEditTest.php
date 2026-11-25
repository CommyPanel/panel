<?php

use App\Audit\Severity;
use App\Databases\DatabaseBackups;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Databases\RestoreDatabase;
use App\Databases\UserPrivileges;
use App\Enums\DatabaseEngine;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Changing what a database user may do: ordinary users only on their own database (MySQL and MariaDB), the
 * administrator of a server made for one app on the whole server, always with a recent password.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = function (DatabaseEngine $engine = DatabaseEngine::Mysql, string $version = '8.4'): ManagedDatabase {
        $server = app(DatabaseServers::class)->create('Main', $engine, $version)->fresh();

        return app(ManageDatabases::class)->create($server, 'shop');
    };
    $this->admin = function (DatabaseEngine $engine = DatabaseEngine::Mysql, string $version = '8.4'): ManagedDatabase {
        $app = App::factory()->create(['slug' => 'blog']);
        $server = app(DatabaseServers::class)->create('Blog db', $engine, $version)->fresh();
        $server->forceFill(['dedicated_app_id' => $app->id])->save();

        return app(ManageDatabases::class)->create($server->fresh(), 'blog', admin: true);
    };
    $this->save = fn (ManagedDatabase $database, array $privileges) => $this->withSession(['auth.password_confirmed_at' => time()])
        ->put("/databases/{$database->server->slug}/databases/{$database->id}/privileges", ['privileges' => $privileges]);
    $this->calls = fn () => collect($this->host->actions())->where('action', 'setDatabasePrivileges')->values();
});

describe('an ordinary user', function () {
    it('can be narrowed on its own database, and the call names the privileges the way the agent knows them', function () {
        $database = ($this->shop)();

        ($this->save)($database, ['select', 'insert', 'create_temporary_tables', 'show_view'])->assertSessionHasNoErrors();

        $call = ($this->calls)()->sole()['args'];

        expect($call)->toMatchArray(['database' => 'shop', 'user' => 'shop', 'scope' => 'database', 'grant_option' => false])
            ->and($call['privileges'])->toBe(['SELECT', 'INSERT', 'CREATE TEMPORARY TABLES', 'SHOW VIEW'])
            ->and($database->fresh()->privileges)->toBe(['privileges' => ['select', 'insert', 'create_temporary_tables', 'show_view'], 'grant_option' => false]);
    });

    it('shows the truth afterwards, and which rows were changed', function () {
        $database = ($this->shop)();
        ($this->save)($database, ['select']);

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->where('permissions.users.0.custom', true)
            ->where('permissions.users.0.granted.select', true)
            ->where('permissions.users.0.granted.insert', false)
            ->where('permissions.users.0.granted.create_user', false)
            ->where('permissions.users.0.editable_keys', UserPrivileges::DATABASE_KEYS)
            ->where('permissions.presets.read_only', ['select', 'show_view', 'execute']));
    });

    it('goes back to the default when everything is ticked again', function () {
        $database = ($this->shop)();
        ($this->save)($database, ['select']);

        ($this->save)($database, UserPrivileges::DATABASE_KEYS)->assertSessionHasNoErrors();

        expect($database->fresh()->privileges)->toBeNull();
    });

    it('is refused any server-wide right, the grant option and names that do not exist', function (string $name) {
        $database = ($this->shop)();

        ($this->save)($database, ['select', $name])->assertSessionHasErrors('privileges');

        expect(($this->calls)())->toHaveCount(0)->and($database->fresh()->privileges)->toBeNull();
    })->with(['create_user', 'show_databases', 'grant_option', 'super', 'create_databases', 'ALL PRIVILEGES']);

    it('cannot be changed on PostgreSQL', function () {
        $database = ($this->shop)(DatabaseEngine::Postgres, '17');

        ($this->save)($database, [])->assertSessionHasErrors('privileges');

        expect(($this->calls)())->toHaveCount(0);
    });

    it('is audited as a warning, with the names that changed and nothing secret', function () {
        $database = ($this->shop)();

        ($this->save)($database, ['select', 'insert']);

        $log = AuditLog::query()->where('action', 'database.privileges_changed')->sole();

        expect(Severity::of('database.privileges_changed'))->toBe(Severity::WARNING)
            ->and($log->metadata['scope'])->toBe('database')
            ->and($log->metadata['removed'])->toContain('delete', 'drop', 'event')
            ->and($log->metadata['added'])->toBe([])
            ->and(json_encode($log->metadata))->not->toContain($database->password);
    });
});

describe('the administrator of a server made for one app', function () {
    it('is changed on the whole server, with the grant option as a choice, and audited as high-risk', function () {
        $database = ($this->admin)();

        ($this->save)($database, [...UserPrivileges::DATABASE_KEYS, 'create_user', 'grant_option'])->assertSessionHasNoErrors();

        $call = ($this->calls)()->sole()['args'];

        expect($call['scope'])->toBe('server')
            ->and($call['grant_option'])->toBeTrue()
            ->and($call['privileges'])->toContain('CREATE USER')->not->toContain('SHOW DATABASES')
            ->and($database->fresh()->privileges['grant_option'])->toBeTrue();

        $log = AuditLog::query()->where('action', 'database.server_privileges_changed')->sole();

        expect($log->metadata['scope'])->toBe('server')->and($log->metadata['removed'])->toBe(['show_databases'])
            ->and(Severity::of('database.server_privileges_changed'))->toBe(Severity::DANGER);
    });

    it('may have CREATEDB and CREATEROLE switched on or off on PostgreSQL, and nothing else', function () {
        $database = ($this->admin)(DatabaseEngine::Postgres, '17');

        ($this->save)($database, ['create_databases'])->assertSessionHasNoErrors();

        expect(($this->calls)()->sole()['args']['privileges'])->toBe(['CREATEDB']);

        ($this->save)($database, ['create_databases', 'select'])->assertSessionHasErrors('privileges');
    });

    it('sees the whole list as editable', function () {
        ($this->admin)();

        $this->get('/databases/blog-db')->assertInertia(fn (Assert $page) => $page
            ->where('permissions.users.0.scope', 'server')
            ->where('permissions.users.0.editable_keys', [...UserPrivileges::DATABASE_KEYS, 'create_user', 'show_databases', 'grant_option']));
    });
});

describe('who may do it', function () {
    it('needs a recent password', function () {
        $database = ($this->shop)();

        $this->put("/databases/main/databases/{$database->id}/privileges", ['privileges' => ['select']])->assertRedirect();

        expect(($this->calls)())->toHaveCount(0)->and($database->fresh()->privileges)->toBeNull();
    });

    it('needs the permission to manage databases', function () {
        $database = ($this->shop)();
        $this->actingAs(User::factory()->regular()->create());

        ($this->save)($database, ['select'])->assertForbidden();
    });

    it('needs a running server, and says so', function () {
        $database = ($this->shop)();
        $database->server->forceFill(['status' => 'stopped'])->save();

        ($this->save)($database, ['select'])->assertSessionHasErrors('privileges');

        expect(($this->calls)())->toHaveCount(0);
    });

    it('is refused while a backup is being loaded', function () {
        $database = ($this->shop)();
        $database->forceFill(['restore_status' => RestoreDatabase::RESTORING])->save();

        ($this->save)($database, ['select'])->assertSessionHasErrors('privileges');
    });

    it('keeps the old rights when the agent refuses', function () {
        $database = ($this->shop)();
        $this->host->failOn('setDatabasePrivileges');

        ($this->save)($database, ['select'])->assertSessionHasErrors('privileges');

        expect($database->fresh()->privileges)->toBeNull();
    });
});

describe('after a restore over the database', function () {
    it('puts a changed set back, because the restore makes the user again', function () {
        $database = ($this->shop)();
        ($this->save)($database, ['select']);
        $this->host->actions();
        $before = ($this->calls)()->count();

        app(ManageDatabases::class)->reapplyPrivileges($database->fresh());

        $calls = ($this->calls)();

        expect($calls)->toHaveCount($before + 1)->and($calls->last()['args']['privileges'])->toBe(['SELECT']);
    });

    it('puts the administrator\'s rights back even when nobody changed them', function () {
        $database = ($this->admin)();

        app(ManageDatabases::class)->reapplyPrivileges($database->fresh());

        $args = ($this->calls)()->sole()['args'];

        expect($args['scope'])->toBe('server')->and($args['grant_option'])->toBeTrue()->and($args['privileges'])->toContain('CREATE USER', 'SHOW DATABASES');
    });

    it('does nothing for an ordinary user that was never changed', function () {
        $database = ($this->shop)();

        app(ManageDatabases::class)->reapplyPrivileges($database->fresh());

        expect(($this->calls)())->toHaveCount(0);
    });
});

describe('a restore that replaces the database', function () {
    beforeEach(function () {
        $this->dir = storage_path('framework/testing/privileges-'.bin2hex(random_bytes(4)));
        config(['commypanel.data_path' => $this->dir]);
        Sleep::fake();
    });

    afterEach(fn () => File::deleteDirectory($this->dir));

    it('sets a changed set again once the user is made anew', function () {
        $database = ($this->shop)();
        ($this->save)($database, ['select']);
        $backup = app(DatabaseBackups::class)->create($database->fresh())['name'];

        app(RestoreDatabase::class)->startOver($database->server, $database->fresh(), $backup);

        $calls = ($this->calls)();

        expect($calls)->toHaveCount(2)->and($calls->last()['args']['privileges'])->toBe(['SELECT'])
            ->and($database->fresh()->restore_notice)->toBeNull();
    });

    it('says so when the rights cannot be set again, and keeps the restored data', function () {
        $database = ($this->shop)();
        ($this->save)($database, ['select']);
        $backup = app(DatabaseBackups::class)->create($database->fresh())['name'];
        $this->host->failOn('setDatabasePrivileges');

        app(RestoreDatabase::class)->startOver($database->server, $database->fresh(), $backup);

        expect($database->fresh()->restore_notice)->toContain('permissions')->and($database->fresh()->restore_status)->toBeNull();
    });
});

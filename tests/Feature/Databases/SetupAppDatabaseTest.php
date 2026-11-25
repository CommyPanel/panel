<?php

use App\Audit\Severity;
use App\Databases\AppLinks;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Databases\ProvisionDatabaseServer;
use App\Databases\SetupAppDatabase;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Enums\SetupStep;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\DatabaseServer;
use App\Models\ManagedDatabase;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Spatie\Permission\Models\Permission;

/**
 * The database step of the Laravel wizard: four choices, Laravel's own variable names, and a dedicated
 * server whose user may create and drop databases.
 */
beforeEach(function () {
    config(['commypanel.data_path' => '/var/lib/commypanel']);
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->blog = App::factory()->create(['slug' => 'blog', 'name' => 'Blog', 'setup_step' => SetupStep::Settings, 'source_verified_at' => now()]);
    $this->makeServer = fn (string $name = 'Main', DatabaseEngine $engine = DatabaseEngine::Postgres, string $version = '17') => app(DatabaseServers::class)->create($name, $engine, $version)->fresh();
    $this->createCalls = fn () => collect($this->host->actions())->where('action', 'createDatabase')->pluck('args')->values();
    $this->envOf = fn () => app(AppLinks::class)->environment($this->blog->fresh());
});

/** A person with only these permissions (and the one that opens the wizard). */
function wizardUser(string ...$permissions): User
{
    $user = User::factory()->regular()->create();

    foreach (['apps.create', ...$permissions] as $permission) {
        $user->givePermissionTo(Permission::findOrCreate($permission));
    }

    return $user;
}

describe('an existing database', function () {
    it('is linked with Laravel\'s own variable names', function () {
        $server = ($this->makeServer)();
        $database = app(ManageDatabases::class)->create($server, 'shop');

        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])
            ->assertOk()
            ->assertJsonPath('choice.mode', 'existing')
            ->assertJsonPath('choice.status', 'ready')
            ->assertJsonPath('choice.linked.database', 'shop');

        $link = $this->blog->databaseLinks()->firstOrFail();
        $env = ($this->envOf)();

        expect($link->env_style)->toBe('laravel')->and($link->env_prefix)->toBe('DB')
            ->and($env)->toHaveKeys(['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL'])
            ->and($env['DB_CONNECTION'])->toBe('pgsql')
            ->and($env['DB_HOST'])->toBe('main')
            ->and($env['DB_PORT'])->toBe('5432')
            ->and($env['DB_DATABASE'])->toBe('shop')
            ->and($env['DB_USERNAME'])->toBe('shop')
            ->and($env['DB_PASSWORD'])->toBe($database->password)
            ->and($env['DB_URL'])->toStartWith('pgsql://shop:');
    });

    it('names the driver by engine and, for MariaDB, by Laravel version', function (DatabaseEngine $engine, string $version, ?int $laravel, string $driver) {
        $server = ($this->makeServer)('Main', $engine, $version);
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $this->blog->forceFill(['stack_options' => $laravel === null ? null : ['laravel_major' => $laravel]])->save();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])->assertOk();

        $env = ($this->envOf)();

        expect($env['DB_CONNECTION'])->toBe($driver)->and($env['DB_URL'])->toStartWith($driver.'://')
            ->and($env['DB_PORT'])->toBe($engine === DatabaseEngine::Postgres ? '5432' : '3306');
    })->with([
        'PostgreSQL' => [DatabaseEngine::Postgres, '17', 12, 'pgsql'],
        'MySQL' => [DatabaseEngine::Mysql, '8.4', 12, 'mysql'],
        'MariaDB on Laravel 11' => [DatabaseEngine::Mariadb, '11.4', 11, 'mariadb'],
        'MariaDB on Laravel 12' => [DatabaseEngine::Mariadb, '11.4', 12, 'mariadb'],
        'MariaDB on Laravel 10' => [DatabaseEngine::Mariadb, '11.4', 10, 'mysql'],
        'MariaDB, version unknown' => [DatabaseEngine::Mariadb, '11.4', null, 'mysql'],
    ]);

    it('reads the Laravel version the wizard detected when none was chosen', function () {
        $server = ($this->makeServer)('Main', DatabaseEngine::Mariadb, '11.4');
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $this->blog->forceFill(['detected' => ['laravel_major' => 12]])->save();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])->assertOk();

        expect(($this->envOf)()['DB_CONNECTION'])->toBe('mariadb');
    });

    it('refuses a database that does not exist, and one that is being restored', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => 999])->assertUnprocessable()->assertJsonValidationErrors('database_id');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing'])->assertUnprocessable()->assertJsonValidationErrors('database_id');

        $server = ($this->makeServer)();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $database->forceFill(['restore_status' => 'restoring'])->save();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])->assertUnprocessable();

        expect($this->blog->databaseLinks()->count())->toBe(0);
    });
});

describe('a new database on an existing server', function () {
    it('is made with its own user and linked', function () {
        $server = ($this->makeServer)();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'blog_prod'])
            ->assertOk()->assertJsonPath('choice.linked.database', 'blog_prod');

        $database = ManagedDatabase::query()->where('name', 'blog_prod')->firstOrFail();
        $call = ($this->createCalls)()->last();

        expect($database->username)->toBe('blog_prod')
            ->and($call['database'])->toBe('blog_prod')->and($call['admin'])->toBeFalse()
            ->and($this->blog->databaseLinks()->first()->managed_database_id)->toBe($database->id)
            ->and(($this->envOf)()['DB_DATABASE'])->toBe('blog_prod');
    });

    it('suggests a name from the app and uses it when none is given', function () {
        $server = ($this->makeServer)();

        $this->getJson('/apps/blog/setup/database')->assertJsonPath('suggested.database', 'blog')->assertJsonPath('suggested.server', 'blog-db');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug])->assertOk();

        expect(ManagedDatabase::query()->pluck('name')->all())->toBe(['blog']);
    });

    it('suggests a valid name for a slug that starts with a digit or is a name the engine keeps', function () {
        $setup = app(SetupAppDatabase::class);

        expect($setup->suggestedDatabase(new App(['slug' => '2fast'])))->toBe('app_2fast')
            ->and($setup->suggestedDatabase(new App(['slug' => 'my-big-blog'])))->toBe('my_big_blog')
            ->and($setup->suggestedDatabase(new App(['slug' => 'mysql'])))->toBe('mysql_db');
    });

    it('refuses a bad name, a taken name and an unknown server', function () {
        $server = ($this->makeServer)();
        app(ManageDatabases::class)->create($server, 'taken');

        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'Bad Name'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'taken'])->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => 'nowhere', 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('server');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors('server');
    });
});

describe('a dedicated server', function () {
    it('is made for the app, with a first database whose user has the rights of the whole server', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'mysql', 'version' => '8.4', 'name' => 'blog'])
            ->assertOk()
            ->assertJsonPath('choice.mode', 'dedicated')
            ->assertJsonPath('choice.status', 'ready')
            ->assertJsonPath('choice.linked.server', 'blog-db');

        $server = DatabaseServer::query()->firstOrFail();
        $call = ($this->createCalls)()->last();

        expect($server->dedicated_app_id)->toBe($this->blog->id)
            ->and($server->slug)->toBe('blog-db')
            ->and($server->engine)->toBe(DatabaseEngine::Mysql)
            ->and($call['admin'])->toBeTrue()->and($call['database'])->toBe('blog')
            ->and(($this->envOf)()['DB_CONNECTION'])->toBe('mysql')
            ->and($this->blog->databaseLinks()->first()->env_style)->toBe('laravel');
    });

    it('waits while the server starts, tells so, and finishes the database by itself afterwards', function () {
        Queue::fake();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17', 'name' => 'blog'])
            ->assertOk()
            ->assertJsonPath('choice.status', 'pending')
            ->assertJsonPath('choice.server_status', 'provisioning')
            ->assertJsonPath('choice.linked', null);

        $this->getJson('/apps/blog/setup/database')->assertJsonPath('choice.status', 'pending');
        expect($this->blog->databaseLinks()->count())->toBe(0)->and(($this->createCalls)())->toHaveCount(0);

        app(ProvisionDatabaseServer::class)(DatabaseServer::query()->firstOrFail());

        $this->getJson('/apps/blog/setup/database')
            ->assertJsonPath('choice.status', 'ready')
            ->assertJsonPath('choice.linked.database', 'blog');

        expect($this->blog->databaseLinks()->count())->toBe(1)->and(($this->createCalls)()->last()['admin'])->toBeTrue();

        // Asking again makes nothing twice.
        $this->getJson('/apps/blog/setup/database')->assertOk();
        expect(($this->createCalls)())->toHaveCount(1);
    });

    it('says why when the server could not start, and starts it again when chosen again', function () {
        Queue::fake();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17'])->assertOk();
        $server = DatabaseServer::query()->firstOrFail();
        $server->forceFill(['status' => DatabaseServerStatus::Failed, 'error' => 'no space left on device'])->save();

        $this->getJson('/apps/blog/setup/database')
            ->assertJsonPath('choice.status', 'failed')
            ->assertJsonPath('choice.error', 'no space left on device');

        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17'])->assertOk();

        expect(DatabaseServer::query()->count())->toBe(1)
            ->and($server->fresh()->status)->toBe(DatabaseServerStatus::Provisioning);
    });

    it('does not make a second server when chosen again', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17', 'name' => 'blog'])->assertOk();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17', 'name' => 'blog2'])->assertOk();

        expect(DatabaseServer::query()->count())->toBe(1)->and($this->blog->databaseLinks()->count())->toBe(1)
            ->and($this->blog->databaseLinks()->first()->database->name)->toBe('blog2');
    });

    it('refuses an engine or version that is not offered', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'oracle', 'version' => '1'])->assertUnprocessable()->assertJsonValidationErrors('engine');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '3'])->assertUnprocessable();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated'])->assertUnprocessable()->assertJsonValidationErrors(['engine', 'version']);

        expect(DatabaseServer::query()->count())->toBe(0);
    });

    it('is never offered to another app, as a server or as a database', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17', 'name' => 'blog'])->assertOk();
        $other = App::factory()->create(['slug' => 'shop', 'setup_step' => SetupStep::Settings, 'source_verified_at' => now()]);
        $dedicated = DatabaseServer::query()->firstOrFail();
        $theirs = $dedicated->databases()->firstOrFail();
        $plain = ($this->makeServer)('Plain');
        app(ManageDatabases::class)->create($plain, 'plain_db');

        $answer = $this->getJson('/apps/shop/setup/database')->assertOk();

        expect(collect($answer->json('servers'))->pluck('slug')->all())->toBe(['plain'])
            ->and(collect($answer->json('databases'))->pluck('name')->all())->toBe(['plain_db']);

        $this->putJson('/apps/shop/setup/database', ['mode' => 'existing', 'database_id' => $theirs->id])->assertUnprocessable();
        $this->putJson('/apps/shop/setup/database', ['mode' => 'new', 'server' => $dedicated->slug, 'name' => 'mine'])->assertUnprocessable();

        expect($other->databaseLinks()->count())->toBe(0);
    });
});

describe('no database', function () {
    it('links nothing and counts as done', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])
            ->assertOk()->assertJsonPath('choice.mode', 'none')->assertJsonPath('choice.status', 'ready');

        expect($this->blog->databaseLinks()->count())->toBe(0)->and(($this->envOf)())->toBe([]);
    });

    it('replaces a database that was chosen before, and leaves that database where it is', function () {
        $server = ($this->makeServer)();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'first'])->assertOk();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'second'])->assertOk();

        expect($this->blog->databaseLinks()->count())->toBe(1)
            ->and(($this->envOf)()['DB_DATABASE'])->toBe('second')
            ->and(ManagedDatabase::query()->count())->toBe(2);

        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])->assertOk();

        expect($this->blog->databaseLinks()->count())->toBe(0)->and(ManagedDatabase::query()->count())->toBe(2);
    });
});

describe('who may do what', function () {
    it('lets a person without the permission to link apps choose only "no database"', function () {
        $server = ($this->makeServer)();
        $this->actingAs(wizardUser('databases.view', 'databases.manage'));

        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug])->assertForbidden();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17'])->assertForbidden();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])->assertOk();

        expect(ManagedDatabase::query()->count())->toBe(0)->and(DatabaseServer::query()->count())->toBe(1);
    });

    it('needs the databases permission to make a database or a server, but not to link an existing one', function () {
        $server = ($this->makeServer)();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $this->actingAs(wizardUser('apps.links', 'databases.view'));

        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug])->assertForbidden();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17'])->assertForbidden();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])->assertOk();
    });

    it('lists nothing to a person who may not view databases', function () {
        $server = ($this->makeServer)();
        app(ManageDatabases::class)->create($server, 'shop');
        $this->actingAs(wizardUser('apps.links'));

        $this->getJson('/apps/blog/setup/database')->assertOk()
            ->assertJsonPath('servers', [])->assertJsonPath('databases', [])
            ->assertJsonPath('can.link', true)->assertJsonPath('can.manage', false);
    });

    it('needs the permission that opens the wizard', function () {
        $user = User::factory()->regular()->create();
        $this->actingAs($user);

        $this->getJson('/apps/blog/setup/database')->assertForbidden();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])->assertForbidden();
    });

    it('is only for a draft that is past the source step', function () {
        $this->blog->forceFill(['setup_step' => SetupStep::Source])->save();
        $this->getJson('/apps/blog/setup/database')->assertNotFound();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])->assertNotFound();

        $this->blog->forceFill(['setup_step' => null])->save();
        $this->getJson('/apps/blog/setup/database')->assertNotFound();

        $this->blog->forceFill(['setup_step' => SetupStep::Database])->save();
        $this->getJson('/apps/blog/setup/database')->assertOk();
    });

    it('rejects a mode that does not exist', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'cloud'])->assertUnprocessable()->assertJsonValidationErrors('mode');
        $this->putJson('/apps/blog/setup/database', [])->assertUnprocessable();
    });
});

describe('what is recorded and shown', function () {
    it('audits every choice with names only, never a password', function () {
        $this->putJson('/apps/blog/setup/database', ['mode' => 'dedicated', 'engine' => 'postgres', 'version' => '17', 'name' => 'blog'])->assertOk();
        $server = ($this->makeServer)('Other');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'second'])->assertOk();

        $actions = AuditLog::query()->pluck('action')->all();
        $everything = json_encode(AuditLog::query()->get()->toArray());
        $passwords = [...DatabaseServer::query()->get()->map->root_password->all(), ...ManagedDatabase::query()->get()->map->password->all()];

        expect($actions)->toContain('database_server.created', 'database.created', 'app.database_chosen', 'app.database_linked')
            ->and(AuditLog::query()->where('action', 'app.database_chosen')->count())->toBe(2)
            ->and(Severity::of('app.database_chosen'))->toBe(Severity::INFO)
            ->and($passwords)->not->toBeEmpty();

        foreach ($passwords as $password) {
            expect($everything)->not->toContain($password);
        }

        $chosen = AuditLog::query()->where('action', 'app.database_chosen')->latest('id')->firstOrFail();

        expect($chosen->metadata)->toMatchArray(['app' => 'blog', 'mode' => 'new', 'server' => 'other', 'database' => 'second']);
    });

    it('never puts a password in what the page gets', function () {
        $server = ($this->makeServer)();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $this->putJson('/apps/blog/setup/database', ['mode' => 'existing', 'database_id' => $database->id])->assertOk();

        $body = $this->getJson('/apps/blog/setup/database')->getContent();

        expect($body)->not->toContain($database->password)->not->toContain($server->root_password)->not->toContain('password');
        expect(json_encode(DatabaseServer::query()->get()->toArray()))->not->toContain($server->root_password);
    });

    it('keeps the choice in the app, without a secret', function () {
        $server = ($this->makeServer)();
        $this->putJson('/apps/blog/setup/database', ['mode' => 'new', 'server' => $server->slug, 'name' => 'shop'])->assertOk();

        expect($this->blog->fresh()->stack_options['database_step'])->toBe(['mode' => 'new', 'status' => 'ready', 'server' => 'main', 'database' => 'shop']);
    });

    it('keeps what other steps stored in the app\'s options', function () {
        $this->blog->forceFill(['stack_options' => ['php' => '8.4', 'node_manager' => 'pnpm']])->save();

        $this->putJson('/apps/blog/setup/database', ['mode' => 'none'])->assertOk();

        expect($this->blog->fresh()->stack_options)->toMatchArray(['php' => '8.4', 'node_manager' => 'pnpm'])
            ->toHaveKey('database_step');
    });
});

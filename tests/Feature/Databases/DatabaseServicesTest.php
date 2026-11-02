<?php

use App\Databases\AppLinks;
use App\Databases\DatabaseConnection;
use App\Databases\DatabaseException;
use App\Databases\DatabaseNames;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Databases\ProvisionDatabaseServer;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\DatabaseTarget;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\ProvisionDatabaseServerJob;
use App\Models\App;
use App\Models\DatabaseServer;
use App\Models\Deployment;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    config(['commypanel.data_path' => '/var/lib/commypanel']);
    Sleep::fake();
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
});

function runningServer(string $name = 'Main', DatabaseEngine $engine = DatabaseEngine::Postgres, string $version = '17'): DatabaseServer
{
    Queue::fake();
    $server = app(DatabaseServers::class)->create($name, $engine, $version);
    app(ProvisionDatabaseServer::class)($server);

    return $server->fresh();
}

describe('creating a server', function () {
    it('registers it and starts it in the background', function () {
        Queue::fake();

        $server = app(DatabaseServers::class)->create('Main database', DatabaseEngine::Postgres, '17');

        expect($server->slug)->toBe('main-database')
            ->and($server->status)->toBe(DatabaseServerStatus::Provisioning)
            ->and($server->engine)->toBe(DatabaseEngine::Postgres)
            ->and($server->root_password)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($server->containerName())->toBe('db-main-database')
            ->and($server->networkName())->toBe('commypanel-db-main-database');

        Queue::assertPushed(ProvisionDatabaseServerJob::class, fn ($job) => $job->serverId === $server->id);
    });

    it('keeps the administrator password encrypted and out of the page data', function () {
        Queue::fake();
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Mariadb, '11.4');

        expect(DB::table('database_servers')->value('root_password'))->not->toContain($server->root_password)
            ->and($server->toArray())->not->toHaveKey('root_password');
    });

    it('only offers the versions it lists', function () {
        Queue::fake();

        expect(fn () => app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '9.6'))
            ->toThrow(DatabaseException::class, 'not one of the versions offered')
            ->and(fn () => app(DatabaseServers::class)->create('Main', DatabaseEngine::Mariadb, 'latest'))
            ->toThrow(DatabaseException::class);

        Queue::assertNothingPushed();
        expect(DatabaseServer::query()->count())->toBe(0);
    });

    it('gives servers with the same name different slugs that end in a letter', function () {
        Queue::fake();

        $slugs = collect(range(1, 4))->map(fn () => app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->slug)->all();

        expect($slugs)->toBe(['main', 'main-a', 'main-b', 'main-c'])
            ->and(collect($slugs)->every(fn ($s) => preg_match('/[a-z]$/', $s) === 1))->toBeTrue();
    });

    it('never gives a server a slug that could share an app\'s network', function () {
        App::factory()->create(['slug' => 'db-main']);

        expect(DatabaseNames::serverSlug('Main'))->toBe('main-a')
            ->and(DatabaseNames::serverSlug('12345'))->toBe('database')
            ->and(DatabaseNames::serverSlug('shop 2'))->toBe('shop');
    });
});

describe('starting a server', function () {
    it('downloads the image, runs it with no published port and its data in a folder of its own', function () {
        $server = runningServer('Main', DatabaseEngine::Postgres, '17');

        /** @var ContainerSpec $spec */
        $spec = collect($this->host->actions())->firstWhere('action', 'runContainer')['args']['spec'];

        expect($this->host->pulledImages())->toBe(['postgres:17'])
            ->and($spec->name)->toBe('db-main')
            ->and($spec->image)->toBe('postgres:17')
            ->and($spec->hostPort)->toBe(0)
            ->and($spec->containerPort)->toBe(0)
            ->and($spec->network)->toBe('commypanel-db-main')
            ->and($spec->memoryLimitMb)->toBe(1024)
            ->and($spec->env)->toBe(['POSTGRES_PASSWORD' => $server->root_password])
            ->and($spec->volumes[0]->source)->toBe('/var/lib/commypanel/databases/main')
            ->and($spec->volumes[0]->target)->toBe('/var/lib/postgresql/data')
            ->and($server->status)->toBe(DatabaseServerStatus::Running);
    });

    it('sets the administrator password the way each image expects', function (DatabaseEngine $engine, string $version, string $variable, string $path) {
        $server = runningServer('Db', $engine, $version);

        $spec = collect($this->host->actions())->firstWhere('action', 'runContainer')['args']['spec'];

        expect($spec->env)->toBe([$variable => $server->root_password])
            ->and($spec->volumes[0]->target)->toBe($path);
    })->with([
        'mariadb' => [DatabaseEngine::Mariadb, '11.4', 'MARIADB_ROOT_PASSWORD', '/var/lib/mysql'],
        'mysql' => [DatabaseEngine::Mysql, '8.4', 'MYSQL_ROOT_PASSWORD', '/var/lib/mysql'],
        'postgres' => [DatabaseEngine::Postgres, '16', 'POSTGRES_PASSWORD', '/var/lib/postgresql/data'],
    ]);

    it('waits until the server answers', function () {
        Queue::fake();
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');
        $this->host->startSlowly('db-main');

        app(ProvisionDatabaseServer::class)($server);

        expect($server->fresh()->status)->toBe(DatabaseServerStatus::Running)
            ->and(collect($this->host->actionNames())->filter(fn ($a) => $a === 'databaseReady')->count())->toBe(2);
    });

    it('fails with the reason when the image cannot be downloaded', function () {
        Queue::fake();
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');
        $this->host->failOn('pullImage');

        app(ProvisionDatabaseServer::class)($server);

        expect($server->fresh())->status->toBe(DatabaseServerStatus::Failed)
            ->and($server->fresh()->error)->toContain('Fake failure')
            ->and($this->host->actionNames())->not->toContain('runContainer');
    });

    it('fails when the server never answers', function () {
        Queue::fake();
        config(['commypanel.databases.ready_timeout' => 0]);
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');
        $host = new class extends FakeHostDriver
        {
            public function databaseReady(DatabaseTarget $server): bool
            {
                return false;
            }
        };
        app()->instance(HostDriver::class, $host);

        app(ProvisionDatabaseServer::class)($server);

        expect($server->fresh())->status->toBe(DatabaseServerStatus::Failed)
            ->and($server->fresh()->error)->toContain('did not answer in time');
    });

    it('can be tried again after a failure, and only then', function () {
        Queue::fake();
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');

        expect(fn () => app(DatabaseServers::class)->retry($server))->toThrow(DatabaseException::class);

        $server->update(['status' => DatabaseServerStatus::Failed, 'error' => 'x']);
        app(DatabaseServers::class)->retry($server);

        expect($server->fresh())->status->toBe(DatabaseServerStatus::Provisioning)->error->toBeNull();
        Queue::assertPushed(ProvisionDatabaseServerJob::class, 2);
    });

    it('stops, starts and restarts', function () {
        $server = runningServer();

        app(DatabaseServers::class)->stop($server);
        expect($server->fresh()->status)->toBe(DatabaseServerStatus::Stopped);

        app(DatabaseServers::class)->start($server);
        expect($server->fresh()->status)->toBe(DatabaseServerStatus::Running);

        app(DatabaseServers::class)->restart($server);
        expect($this->host->actionNames())->toContain('stopContainer', 'startContainer', 'restartContainer');
    });

    it('cannot be controlled before it is installed', function () {
        Queue::fake();
        $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');

        expect(fn () => app(DatabaseServers::class)->stop($server))->toThrow(DatabaseException::class, 'not installed yet');
    });
});

describe('databases', function () {
    it('creates a database with a user of the same name and a generated password', function () {
        $server = runningServer();

        $database = app(ManageDatabases::class)->create($server, 'shop');

        expect($database->name)->toBe('shop')
            ->and($database->username)->toBe('shop')
            ->and($database->password)->toMatch('/^[A-Za-z0-9]{32}$/')
            ->and($this->host->databases()['db-main']['shop']['user'])->toBe('shop')
            ->and($this->host->databases()['db-main']['shop']['password'])->toBe($database->password);
    });

    it('refuses names the engines keep, or that are not safe', function (string $name) {
        $server = runningServer();

        expect(fn () => app(ManageDatabases::class)->create($server, $name))->toThrow(DatabaseException::class);
        expect($this->host->databases())->toBe([]);
    })->with(['root' => 'root', 'postgres' => 'postgres', 'mysql' => 'mysql', 'information_schema' => 'information_schema', 'upper' => 'Shop', 'dash' => 'my-shop', 'space' => 'my shop', 'quote' => "sh'op", 'digit first' => '1shop', 'empty' => '', 'long' => str_repeat('a', 32), 'pg prefix' => 'pg_stats']);

    it('refuses a second database of the same name', function () {
        $server = runningServer();
        app(ManageDatabases::class)->create($server, 'shop');

        expect(fn () => app(ManageDatabases::class)->create($server, 'shop'))->toThrow(DatabaseException::class, 'already a database called shop');
    });

    it('needs a running server', function () {
        $server = runningServer();
        app(DatabaseServers::class)->stop($server);

        expect(fn () => app(ManageDatabases::class)->create($server->fresh(), 'shop'))->toThrow(DatabaseException::class, 'not running');
    });

    it('keeps nothing when the server refuses', function () {
        $server = runningServer();
        $this->host->failOn('createDatabase');

        expect(fn () => app(ManageDatabases::class)->create($server, 'shop'))->toThrow(DatabaseException::class, 'Could not create the database');
        expect($server->databases()->count())->toBe(0);
    });

    it('drops a database and its user', function () {
        $server = runningServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');

        app(ManageDatabases::class)->drop($database);

        expect($server->databases()->count())->toBe(0)
            ->and($this->host->databases()['db-main'])->toBe([]);
    });

    it('changes the password and says which apps must be deployed again', function () {
        $server = runningServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $app = App::factory()->create(['name' => 'Shop', 'slug' => 'shop']);
        app(AppLinks::class)->linkDatabase($app, $database, 'DATABASE');
        $old = $database->password;

        $apps = app(ManageDatabases::class)->rotatePassword($database);

        $database->refresh();
        expect($apps)->toBe(['Shop'])
            ->and($database->password)->not->toBe($old)
            ->and($this->host->databases()['db-main']['shop']['password'])->toBe($database->password);
    });

    it('cannot be dropped while an app is linked to it', function () {
        $server = runningServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        app(AppLinks::class)->linkDatabase(App::factory()->create(['name' => 'Shop', 'slug' => 'shop']), $database, 'DATABASE');

        expect(fn () => app(ManageDatabases::class)->drop($database))->toThrow(DatabaseException::class, 'Shop');
        expect($server->databases()->count())->toBe(1);
    });

    it('describes a connection by the server\'s name, with the password hidden when shown', function () {
        $server = runningServer('Main', DatabaseEngine::Postgres, '17');
        $database = app(ManageDatabases::class)->create($server, 'shop');

        $c = DatabaseConnection::for($database->fresh('server'));

        expect($c->host)->toBe('main')
            ->and($c->port)->toBe(5432)
            ->and($c->url)->toBe("postgresql://shop:{$database->password}@main:5432/shop")
            ->and($c->masked())->toBe('postgresql://shop:********@main:5432/shop')
            ->and($c->masked())->not->toContain($database->password);

        $mysql = runningServer('Sql', DatabaseEngine::Mysql, '8.4');
        $db = app(ManageDatabases::class)->create($mysql, 'blog');

        expect(DatabaseConnection::for($db->fresh('server'))->url)->toStartWith('mysql://blog:')->toEndWith('@sql:3306/blog');
    });
});

describe('deleting a server', function () {
    it('removes its container, network and files, and its databases with it', function () {
        $server = runningServer();
        app(ManageDatabases::class)->create($server, 'shop');

        app(DatabaseServers::class)->delete($server);

        $names = $this->host->actionNames();
        expect(DatabaseServer::query()->count())->toBe(0)
            ->and(DB::table('managed_databases')->count())->toBe(0)
            ->and(collect($this->host->actions())->firstWhere('action', 'removeDatabaseData')['args'])->toBe(['server' => 'main'])
            ->and(array_search('removeContainer', array_reverse($names, true), true))->not->toBeFalse();
    });

    it('is refused while an app uses one of its databases', function () {
        $server = runningServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        app(AppLinks::class)->linkDatabase(App::factory()->create(['name' => 'Shop', 'slug' => 'shop']), $database, 'DATABASE');

        expect(fn () => app(DatabaseServers::class)->delete($server))->toThrow(DatabaseException::class, 'Remove their links first');
        expect(DatabaseServer::query()->count())->toBe(1);
    });

    it('keeps the server listed when its files could not be removed, so it can be tried again', function () {
        $server = runningServer();
        $this->host->failOn('removeDatabaseData');

        expect(fn () => app(DatabaseServers::class)->delete($server))->toThrow(DatabaseException::class);
        expect(DatabaseServer::query()->count())->toBe(1);
    });
});

describe('links', function () {
    beforeEach(function () {
        $this->server = runningServer('Main', DatabaseEngine::Postgres, '17');
        $this->database = app(ManageDatabases::class)->create($this->server, 'shop');
        $this->shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop', 'container_port' => 8080]);
    });

    it('lets an app reach a database on a network made for the link alone', function () {
        $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'database');

        expect($link->env_prefix)->toBe('DATABASE')
            ->and($link->networkName())->toBe('commypanel-link-d'.$link->id)
            ->and($this->host->connections()[$link->networkName()])->toBe(['db-main' => ['main']]);
    });

    it('gives the app the connection as environment variables', function () {
        app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');

        $env = app(AppLinks::class)->environment($this->shop);

        expect($env)->toBe([
            'DATABASE_URL' => "postgresql://shop:{$this->database->password}@main:5432/shop",
            'DATABASE_HOST' => 'main',
            'DATABASE_PORT' => '5432',
            'DATABASE_NAME' => 'shop',
            'DATABASE_USER' => 'shop',
            'DATABASE_PASSWORD' => $this->database->password,
        ]);
    });

    it('puts a password with special characters into the URL safely', function () {
        $this->database->forceFill(['password' => 'p@ss/w:rd?#'])->save();
        app(AppLinks::class)->linkDatabase($this->shop, $this->database->fresh(), 'DATABASE');

        expect(app(AppLinks::class)->environment($this->shop)['DATABASE_URL'])->toBe('postgresql://shop:p%40ss%2Fw%3Ard%3F%23@main:5432/shop');
    });

    it('makes the app join the link network when it starts', function () {
        $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');

        $attachments = app(AppLinks::class)->attachments($this->shop);

        expect($attachments)->toHaveCount(1)
            ->and($attachments[0]->name)->toBe($link->networkName())
            ->and($attachments[0]->aliases)->toBe([]);
    });

    it('refuses an unknown prefix, a taken one, a second link to the same database and a stopped server', function () {
        app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
        $other = app(ManageDatabases::class)->create($this->server, 'blog');

        expect(fn () => app(AppLinks::class)->linkDatabase($this->shop, $other, 'DATABASE'))->toThrow(DatabaseException::class, 'already used')
            ->and(fn () => app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'OTHER'))->toThrow(DatabaseException::class, 'already linked')
            ->and(fn () => app(AppLinks::class)->linkDatabase($this->shop, $other, 'bad-name'))->toThrow(DatabaseException::class)
            ->and(fn () => app(AppLinks::class)->linkDatabase($this->shop, $other, 'PORT'))->toThrow(DatabaseException::class, 'important variable');

        app(DatabaseServers::class)->stop($this->server);
        expect(fn () => app(AppLinks::class)->linkDatabase(App::factory()->create(['slug' => 'x1']), $other->fresh('server'), 'DB2'))->toThrow(DatabaseException::class, 'not running');
    });

    it('does not keep the link when the server cannot be connected', function () {
        $this->host->failOn('connectNetwork');

        expect(fn () => app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE'))->toThrow(DatabaseException::class);
        expect($this->shop->databaseLinks()->count())->toBe(0);
    });

    it('is removed again, and the network with it', function () {
        $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
        $network = $link->networkName();

        app(AppLinks::class)->unlinkDatabase($link);

        expect($this->shop->databaseLinks()->count())->toBe(0)
            ->and($this->host->connections())->not->toHaveKey($network)
            ->and($this->host->actionNames())->toContain('disconnectNetwork', 'pruneLinkNetworks');
    });

    it('links one app to another, which answers to its own name', function () {
        $api = App::factory()->create(['slug' => 'api', 'name' => 'Api', 'container_port' => 3000]);

        $link = app(AppLinks::class)->linkApp($this->shop, $api, 'API');

        expect(app(AppLinks::class)->environment($this->shop))->toBe(['API_URL' => 'http://api:3000', 'API_HOST' => 'api', 'API_PORT' => '3000']);

        $consumer = app(AppLinks::class)->attachments($this->shop);
        $provider = app(AppLinks::class)->attachments($api);

        expect($consumer[0]->name)->toBe($link->networkName())->and($consumer[0]->aliases)->toBe([])
            ->and($provider[0]->name)->toBe($link->networkName())->and($provider[0]->aliases)->toBe(['api']);
    });

    it('connects the other app\'s running container at once', function () {
        $api = App::factory()->create(['slug' => 'api', 'container_port' => 3000]);
        $deployment = Deployment::factory()->create(['app_id' => $api->id, 'number' => 2, 'container_name' => 'api-2', 'status' => DeploymentStatus::Active]);
        $api->update(['active_deployment_id' => $deployment->id]);

        $link = app(AppLinks::class)->linkApp($this->shop, $api, 'API');

        expect($this->host->connections()[$link->networkName()])->toBe(['api-2' => ['api']]);
    });

    it('refuses to link an app to itself, twice, or to the panel', function () {
        $api = App::factory()->create(['slug' => 'api']);
        app(AppLinks::class)->linkApp($this->shop, $api, 'API');

        expect(fn () => app(AppLinks::class)->linkApp($this->shop, $this->shop, 'SELF'))->toThrow(DatabaseException::class, 'itself')
            ->and(fn () => app(AppLinks::class)->linkApp($this->shop, $api, 'API2'))->toThrow(DatabaseException::class, 'already linked');
    });

    it('lets go of everything when an app is deleted', function () {
        $dbLink = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
        $api = App::factory()->create(['slug' => 'api']);
        $appLink = app(AppLinks::class)->linkApp($api, $this->shop, 'SHOP');

        app(AppLinks::class)->removeAllFor($this->shop);

        expect($this->shop->databaseLinks()->count())->toBe(0)
            ->and($api->links()->count())->toBe(0)
            ->and($this->host->connections())->not->toHaveKey($dbLink->networkName())
            ->and($this->host->connections())->not->toHaveKey($appLink->networkName());
    });
});

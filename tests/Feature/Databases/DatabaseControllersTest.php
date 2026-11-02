<?php

use App\Databases\AppLinks;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Enums\DatabaseEngine;
use App\Enums\DatabaseServerStatus;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\ProvisionDatabaseServerJob;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\DatabaseServer;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['commypanel.data_path' => '/var/lib/commypanel']);
    Sleep::fake();
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
});

/** A running server, created while the queue is synchronous so it is provisioned. */
function makeServer(string $name = 'Main', DatabaseEngine $engine = DatabaseEngine::Postgres, string $version = '17'): DatabaseServer
{
    return app(DatabaseServers::class)->create($name, $engine, $version)->fresh();
}

describe('servers', function () {
    it('lists them without any secret', function () {
        $server = makeServer();

        $this->get('/databases')->assertInertia(fn (Assert $page) => $page
            ->component('databases/index')
            ->where('servers.0.slug', 'main')
            ->where('servers.0.engine_label', 'PostgreSQL')
            ->where('servers.0.status', 'running')
            ->where('servers.0.host', 'main')
            ->where('servers.0.port', 5432)
            ->missing('servers.0.root_password')
            ->where('engines.2.value', 'postgres')
            ->where('engines.2.versions', ['17', '16', '15']));

        expect(json_encode($this->get('/databases')->viewData('page')['props']))->not->toContain($server->root_password);
    });

    it('creates one and starts it in the background', function () {
        Queue::fake();

        $this->post('/databases', ['name' => 'Main', 'engine' => 'mariadb', 'version' => '11.4'])
            ->assertRedirect('/databases/main')
            ->assertSessionHas('status');

        $server = DatabaseServer::query()->firstOrFail();
        Queue::assertPushed(ProvisionDatabaseServerJob::class, fn ($job) => $job->serverId === $server->id);
        expect($server->status)->toBe(DatabaseServerStatus::Provisioning)
            ->and(AuditLog::query()->where('action', 'database_server.created')->first()->metadata)->toMatchArray(['server' => 'main', 'engine' => 'mariadb', 'version' => '11.4']);
    });

    it('refuses a version that is not offered, or an unknown engine', function () {
        Queue::fake();

        $this->post('/databases', ['name' => 'Main', 'engine' => 'postgres', 'version' => '9.6'])->assertSessionHasErrors('version');
        $this->post('/databases', ['name' => 'Main', 'engine' => 'mongodb', 'version' => '7'])->assertSessionHasErrors('engine');
        $this->post('/databases', ['name' => 'M', 'engine' => 'postgres', 'version' => '17'])->assertSessionHasErrors('name');

        expect(DatabaseServer::query()->count())->toBe(0);
    });

    it('shows a server with its databases and the apps that use them', function () {
        $server = makeServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        $shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop']);
        app(AppLinks::class)->linkDatabase($shop, $database, 'DATABASE');

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->component('databases/show')
            ->where('server.status', 'running')
            ->where('databases.0.name', 'shop')
            ->where('databases.0.username', 'shop')
            ->where('databases.0.apps.0', ['slug' => 'shop', 'name' => 'Shop'])
            ->missing('databases.0.password'));
    });

    it('does not know a server that is not there', function () {
        $this->get('/databases/nothing')->assertNotFound();
    });

    it('stops, starts and restarts it, and records each', function () {
        makeServer();

        $this->post('/databases/main/stop')->assertSessionHas('status');
        expect(DatabaseServer::query()->first()->status)->toBe(DatabaseServerStatus::Stopped);

        $this->post('/databases/main/start')->assertSessionHas('status');
        $this->post('/databases/main/restart')->assertSessionHas('status');

        expect(DatabaseServer::query()->first()->status)->toBe(DatabaseServerStatus::Running)
            ->and(AuditLog::query()->where('action', 'like', 'database_server.%')->pluck('action')->all())->toContain('database_server.stopped', 'database_server.started', 'database_server.restarted');
    });

    it('starts again after a failed start, and only then', function () {
        Queue::fake();
        $this->post('/databases', ['name' => 'Main', 'engine' => 'postgres', 'version' => '17']);

        $this->post('/databases/main/retry')->assertSessionHasErrors('database');

        DatabaseServer::query()->update(['status' => 'failed', 'error' => 'x']);
        $this->post('/databases/main/retry')->assertSessionHas('status');

        Queue::assertPushed(ProvisionDatabaseServerJob::class, 2);
    });

    it('deletes it only after its name is typed, and with the password', function () {
        makeServer();

        $this->delete('/databases/main', ['confirm' => 'main'])->assertRedirect();
        expect(DatabaseServer::query()->count())->toBe(1);

        ($this->confirmed)()->delete('/databases/main', ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
        expect(DatabaseServer::query()->count())->toBe(1);

        ($this->confirmed)()->delete('/databases/main', ['confirm' => 'main'])->assertRedirect('/databases');

        expect(DatabaseServer::query()->count())->toBe(0)
            ->and($this->host->actionNames())->toContain('removeContainer', 'removeDatabaseData')
            ->and(AuditLog::query()->where('action', 'database_server.deleted')->count())->toBe(1);
    });

    it('refuses to delete a server whose database an app uses', function () {
        $server = makeServer();
        $database = app(ManageDatabases::class)->create($server, 'shop');
        app(AppLinks::class)->linkDatabase(App::factory()->create(['slug' => 'shop', 'name' => 'Shop']), $database, 'DATABASE');

        ($this->confirmed)()->delete('/databases/main', ['confirm' => 'main'])->assertSessionHasErrors('confirm');

        expect(DatabaseServer::query()->count())->toBe(1);
    });
});

describe('databases', function () {
    beforeEach(function () {
        $this->server = makeServer();
    });

    it('creates one and records it', function () {
        $this->post('/databases/main/databases', ['name' => 'shop'])->assertSessionHas('status');

        $database = $this->server->databases()->first();
        expect($database->name)->toBe('shop')
            ->and($this->host->databases()['db-main'])->toHaveKey('shop')
            ->and(AuditLog::query()->where('action', 'database.created')->first()->metadata)->toBe(['server' => 'main', 'database' => 'shop'])
            ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($database->password);
    });

    it('refuses bad names with a reason', function (string $name) {
        $this->post('/databases/main/databases', ['name' => $name])->assertSessionHasErrors('name');

        expect($this->server->databases()->count())->toBe(0);
    })->with(['reserved' => 'postgres', 'root' => 'root', 'upper case' => 'Shop', 'a dash' => 'my-db', 'a quote' => "x'y"]);

    it('drops one only after its name is typed', function () {
        $database = app(ManageDatabases::class)->create($this->server, 'shop');

        ($this->confirmed)()->delete("/databases/main/databases/{$database->id}", ['confirm' => 'nope'])->assertSessionHasErrors('confirm');
        expect($this->server->databases()->count())->toBe(1);

        ($this->confirmed)()->delete("/databases/main/databases/{$database->id}", ['confirm' => 'shop'])->assertSessionHas('status');
        expect($this->server->databases()->count())->toBe(0);
    });

    it('cannot be dropped through another server\'s address', function () {
        $database = app(ManageDatabases::class)->create($this->server, 'shop');
        makeServer('Other');

        ($this->confirmed)()->delete("/databases/other/databases/{$database->id}", ['confirm' => 'shop'])->assertNotFound();

        expect($this->server->databases()->count())->toBe(1);
    });

    it('changes the password, tells which apps need a new deployment, and records it without the password', function () {
        $database = app(ManageDatabases::class)->create($this->server, 'shop');
        app(AppLinks::class)->linkDatabase(App::factory()->create(['slug' => 'shop', 'name' => 'Shop']), $database, 'DATABASE');
        $old = $database->password;

        ($this->confirmed)()->post("/databases/main/databases/{$database->id}/password")
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'Deploy Shop again'));

        $database->refresh();
        expect($database->password)->not->toBe($old)
            ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($database->password)->not->toContain($old);
    });

    it('asks for the password before changing it', function () {
        $database = app(ManageDatabases::class)->create($this->server, 'shop');

        $this->post("/databases/main/databases/{$database->id}/password")->assertRedirect();

        expect($this->host->actionNames())->not->toContain('setDatabasePassword');
    });
});

describe('credentials', function () {
    beforeEach(function () {
        $this->server = makeServer();
        $this->database = app(ManageDatabases::class)->create($this->server, 'shop');
    });

    it('shows the connection, never cached, and records every look', function () {
        $response = ($this->confirmed)()->getJson("/databases/main/databases/{$this->database->id}/credentials")->assertOk();

        expect($response->json())->toBe([
            'host' => 'main',
            'port' => 5432,
            'name' => 'shop',
            'user' => 'shop',
            'password' => $this->database->password,
            'url' => "postgresql://shop:{$this->database->password}@main:5432/shop",
        ])->and($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(AuditLog::query()->where('action', 'database.credentials_viewed')->count())->toBe(1);
    });

    it('needs a recent password confirmation and writes nothing without it', function () {
        $this->getJson("/databases/main/databases/{$this->database->id}/credentials")->assertStatus(423);

        expect(AuditLog::query()->where('action', 'database.credentials_viewed')->count())->toBe(0);
    });

    it('is not part of any page', function () {
        $page = json_encode($this->get('/databases/main')->viewData('page')['props']);

        expect($page)->not->toContain($this->database->password)->not->toContain($this->server->root_password);
    });
});

describe('links from an app', function () {
    beforeEach(function () {
        $this->server = makeServer();
        $this->database = app(ManageDatabases::class)->create($this->server, 'shop');
        $this->shop = App::factory()->create(['slug' => 'shop', 'name' => 'Shop', 'container_port' => 8080]);
        $this->api = App::factory()->create(['slug' => 'api', 'name' => 'Api', 'container_port' => 3000]);
    });

    it('lists what can be linked, and what already is', function () {
        app(AppLinks::class)->linkApp($this->api, $this->shop, 'SHOP');

        $json = $this->getJson('/apps/shop/links')->assertOk()->json();

        expect($json['available_databases'][0]['label'])->toBe('shop on Main (PostgreSQL 17)')
            ->and(collect($json['available_apps'])->pluck('slug')->all())->toBe(['api'])
            ->and($json['used_by'])->toBe([['app' => 'Api', 'slug' => 'api']])
            ->and($json['databases'])->toBe([])
            ->and(json_encode($json))->not->toContain($this->database->password);
    });

    it('does not offer a database on a stopped server', function () {
        app(DatabaseServers::class)->stop($this->server);

        expect($this->getJson('/apps/shop/links')->json('available_databases'))->toBe([]);
    });

    it('links a database, tells the user it applies from the next deployment, and records it', function () {
        $this->post('/apps/shop/links/database', ['database_id' => $this->database->id, 'env_prefix' => 'database'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'next deployment') && str_contains($m, 'DATABASE_URL'));

        $json = $this->getJson('/apps/shop/links')->json();

        expect($json['databases'][0])->toMatchArray(['prefix' => 'DATABASE', 'database' => 'shop', 'server_slug' => 'main', 'engine' => 'PostgreSQL', 'running' => true])
            ->and($json['available_databases'])->toBe([])
            ->and(AuditLog::query()->where('action', 'app.database_linked')->first()->metadata)->toMatchArray(['app' => 'shop', 'database' => 'shop', 'prefix' => 'DATABASE']);
    });

    it('explains why a link cannot be made', function () {
        $this->post('/apps/shop/links/database', ['database_id' => $this->database->id, 'env_prefix' => 'PORT'])->assertSessionHasErrors('link');
        $this->post('/apps/shop/links/database', ['database_id' => $this->database->id, 'env_prefix' => 'bad name'])->assertSessionHasErrors('link');
        $this->post('/apps/shop/links/database', ['database_id' => 9999, 'env_prefix' => 'DATABASE'])->assertSessionHasErrors('database_id');
        $this->post('/apps/shop/links/app', ['target' => 'shop', 'env_prefix' => 'SELF'])->assertSessionHasErrors('link');
        $this->post('/apps/shop/links/app', ['target' => 'nothing', 'env_prefix' => 'X'])->assertSessionHasErrors('target');

        expect($this->shop->databaseLinks()->count() + $this->shop->links()->count())->toBe(0);
    });

    it('links another app and says where it can be reached', function () {
        $this->post('/apps/shop/links/app', ['target' => 'api', 'env_prefix' => 'API'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, 'http://api:3000'));

        expect($this->getJson('/apps/shop/links')->json('apps.0'))->toMatchArray(['prefix' => 'API', 'slug' => 'api', 'app' => 'Api'])
            ->and(AuditLog::query()->where('action', 'app.app_linked')->count())->toBe(1);
    });

    it('removes links again', function () {
        $databaseLink = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
        $appLink = app(AppLinks::class)->linkApp($this->shop, $this->api, 'API');

        $this->delete("/apps/shop/links/database/{$databaseLink->id}")->assertSessionHas('status');
        $this->delete("/apps/shop/links/app/{$appLink->id}")->assertSessionHas('status');

        expect($this->shop->databaseLinks()->count())->toBe(0)
            ->and($this->shop->links()->count())->toBe(0)
            ->and(AuditLog::query()->whereIn('action', ['app.database_unlinked', 'app.app_unlinked'])->count())->toBe(2);
    });

    it('cannot remove another app\'s link through this app\'s address', function () {
        $link = app(AppLinks::class)->linkDatabase($this->api, $this->database, 'DATABASE');

        $this->delete("/apps/shop/links/database/{$link->id}")->assertNotFound();

        expect($this->api->databaseLinks()->count())->toBe(1);
    });
});

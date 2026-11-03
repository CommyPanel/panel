<?php

use App\Databases\DatabaseServers;
use App\Databases\DatabaseTools;
use App\Databases\ManageDatabases;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Enums\DatabaseEngine;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\AuditLog;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['commypanel.tools_port' => 2053]);
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->maria = app(DatabaseServers::class)->create('Main', DatabaseEngine::Mariadb, '11.4')->fresh();
    $this->shop = app(ManageDatabases::class)->create($this->maria, 'shop');
    $this->blog = app(ManageDatabases::class)->create($this->maria, 'blog');
    $this->pg = app(DatabaseServers::class)->create('Reports', DatabaseEngine::Postgres, '17')->fresh();
    $this->sales = app(ManageDatabases::class)->create($this->pg, 'sales');

    $this->url = fn ($db, string $suffix = '') => "/databases/{$db->server->slug}/databases/{$db->id}/tool{$suffix}";
});

it('names the tool after the engine: phpMyAdmin for MySQL and MariaDB, Adminer for PostgreSQL', function () {
    expect(DatabaseTools::toolName($this->shop))->toBe('phpMyAdmin')
        ->and(DatabaseTools::toolName($this->sales))->toBe('Adminer')
        ->and(DatabaseTools::toolName($this->pg))->toBe('Adminer');

    $this->get('/databases/'.$this->pg->slug)->assertInertia(fn (Assert $page) => $page->where('server.tool', 'Adminer'));
    $this->get('/databases/'.$this->maria->slug)->assertInertia(fn (Assert $page) => $page->where('server.tool', 'phpMyAdmin'));
});

describe('the page that opens a tool', function () {
    it('asks for the password first, then says what is about to happen', function () {
        $this->get(($this->url)($this->shop))->assertRedirect();

        ($this->confirmed)()->get(($this->url)($this->shop))->assertInertia(fn (Assert $page) => $page
            ->component('databases/tool')
            ->where('tool', 'phpMyAdmin')
            ->where('database.name', 'shop')
            ->where('database.user', 'shop')
            ->where('idle_minutes', 15));

        ($this->confirmed)()->get(($this->url)($this->sales))->assertInertia(fn (Assert $page) => $page->where('tool', 'Adminer'));
    });

    it('is only for people allowed to open databases in a tool', function () {
        $this->actingAs(User::factory()->create(['is_super' => false]));

        foreach ([['get', ''], ['post', ''], ['get', '/abcdefghijklmnopqrstuvwx'], ['post', '/abcdefghijklmnopqrstuvwx/open'], ['post', '/abcdefghijklmnopqrstuvwx/stop']] as [$method, $suffix]) {
            ($this->confirmed)()->{$method}(($this->url)($this->shop, $suffix))->assertForbidden();
        }
        expect($this->host->actionNames())->not->toContain('startDatabaseTool');
    });
});

describe('starting', function () {
    it('starts the tool for that database as its own user, on the tools port, without the password leaving the panel in a record', function () {
        config(['commypanel.tools_port' => 2087]);

        $response = ($this->confirmed)()->postJson(($this->url)($this->shop))->assertOk();

        $call = collect($this->host->actions())->firstWhere('action', 'startDatabaseTool');
        expect($response->json('stage'))->toBe('pulling')
            ->and($response->json('session'))->toBeString()->toHaveLength(24)
            ->and($call['args'])->toMatchArray(['engine' => 'mariadb', 'server' => $this->maria->slug, 'database' => 'shop', 'user' => 'shop', 'public_base' => 'https://localhost:2087'])
            ->and(json_encode($call['args']))->not->toContain($this->shop->password);

        $entry = AuditLog::query()->where('action', 'database.tool_started')->first();
        expect($entry->metadata)->toBe(['server' => $this->maria->slug, 'database' => 'shop', 'tool' => 'phpmyadmin'])
            ->and(json_encode($entry->metadata))->not->toContain($this->shop->password);
    });

    it('uses Adminer for PostgreSQL', function () {
        ($this->confirmed)()->postJson(($this->url)($this->sales))->assertOk();

        $call = collect($this->host->actions())->firstWhere('action', 'startDatabaseTool');
        expect($call['args']['engine'])->toBe('postgres');
    });

    it('reaches the tools on the address the person is using, IPv6 included', function () {
        app(PanelDomain::class)->set('commy.example.com');
        app(ServerAddress::class)->save('203.0.113.7', '2001:db8::7');

        ($this->confirmed)()->postJson('http://commy.example.com'.($this->url)($this->shop))->assertOk();
        ($this->confirmed)()->postJson('http://[2001:db8::7]'.($this->url)($this->blog))->assertOk();
        ($this->confirmed)()->postJson('http://203.0.113.7'.($this->url)($this->sales))->assertOk();

        $bases = collect($this->host->actions())->where('action', 'startDatabaseTool')->pluck('args.public_base')->all();
        expect($bases)->toBe(['https://commy.example.com:2053', 'https://[2001:db8::7]:2053', 'https://203.0.113.7:2053']);
    });

    it('never sends a ticket to a name the panel does not answer on, whatever the Host header says', function () {
        app(PanelDomain::class)->set('commy.example.com');
        app(ServerAddress::class)->save('203.0.113.7', null);

        ($this->confirmed)()->postJson('http://evil.example'.($this->url)($this->shop))->assertOk();

        $call = collect($this->host->actions())->firstWhere('action', 'startDatabaseTool');
        expect($call['args']['public_base'])->toBe('https://commy.example.com:2053');
    });

    it('says why it cannot start', function () {
        app(DatabaseServers::class)->stop($this->maria);
        ($this->confirmed)()->postJson(($this->url)($this->shop))->assertStatus(422)->assertJsonPath('error', 'The database server is not running. Start it first.');

        $busy = $this->pg->databases()->create(['name' => 'busy', 'username' => 'busy', 'password' => 'x', 'restore_status' => 'restoring', 'restore_from' => 'f']);
        ($this->confirmed)()->postJson(($this->url)($busy))->assertStatus(422);

        expect($this->host->actionNames())->not->toContain('startDatabaseTool');
    });

    it('needs the password, and is rate limited', function () {
        $this->postJson(($this->url)($this->shop))->assertStatus(423);

        for ($i = 0; $i < 10; $i++) {
            ($this->confirmed)()->postJson(($this->url)($this->shop));
        }
        ($this->confirmed)()->postJson(($this->url)($this->shop))->assertStatus(429);
    });
});

describe('following it and opening it', function () {
    beforeEach(function () {
        $this->session = ($this->confirmed)()->postJson(($this->url)($this->shop))->json('session');
    });

    it('reports the stage and the reason of a failure', function () {
        ($this->confirmed)()->getJson(($this->url)($this->shop, '/'.$this->session))->assertOk()->assertJson(['stage' => 'ready', 'error' => null]);

        $this->host->setToolStage('failed', 'The tool could not be started.');
        ($this->confirmed)()->getJson(($this->url)($this->shop, '/'.$this->session))->assertJson(['stage' => 'failed', 'error' => 'The tool could not be started.']);
    });

    it('knows no session of another database, or one that is gone', function () {
        ($this->confirmed)()->getJson(($this->url)($this->blog, '/'.$this->session))->assertNotFound()->assertJson(['gone' => true]);
        ($this->confirmed)()->postJson(($this->url)($this->blog, '/'.$this->session.'/open'))->assertStatus(422);
        ($this->confirmed)()->getJson(($this->url)($this->shop, '/zzzzzzzzzzzzzzzzzzzzzzzz'))->assertNotFound();
        ($this->confirmed)()->getJson(($this->url)($this->shop, '/short'))->assertNotFound();

        expect($this->host->actionNames())->not->toContain('databaseToolTicket');
    });

    it('gives the address with a single-use ticket once it is ready, and records it', function () {
        $response = ($this->confirmed)()->postJson(($this->url)($this->shop, '/'.$this->session.'/open'))->assertOk();

        expect($response->json('url'))->toStartWith('https://localhost:2053/open?t=ticket-')
            ->and(AuditLog::query()->where('action', 'database.tool_opened')->first()->metadata)
            ->toBe(['server' => $this->maria->slug, 'database' => 'shop', 'tool' => 'phpMyAdmin']);
    });

    it('does not give an address while it is not ready', function () {
        $this->host->setToolStage('pulling');

        ($this->confirmed)()->postJson(($this->url)($this->shop, '/'.$this->session.'/open'))->assertStatus(422);

        expect($this->host->actionNames())->not->toContain('databaseToolTicket');
    });

    it('can be closed by hand, once, and only for its own database', function () {
        ($this->confirmed)()->postJson(($this->url)($this->blog, '/'.$this->session.'/stop'))->assertOk();
        expect($this->host->actionNames())->not->toContain('stopDatabaseTool');

        ($this->confirmed)()->postJson(($this->url)($this->shop, '/'.$this->session.'/stop'))->assertOk();

        expect($this->host->actionNames())->toContain('stopDatabaseTool')
            ->and(AuditLog::query()->where('action', 'database.tool_closed')->exists())->toBeTrue();
    });
});

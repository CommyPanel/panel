<?php

use App\Audit\Severity;
use App\Databases\DatabaseException;
use App\Databases\DatabaseServers;
use App\Databases\RemoteAccess;
use App\Databases\RemoteAllowList;
use App\Dns\ServerAddress;
use App\Enums\DatabaseEngine;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['commypanel.data_path' => '/var/lib/commypanel']);
    Sleep::fake();
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    app(ServerAddress::class)->save('203.0.113.9', null);
    $this->server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17')->fresh();
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);
});

function openRemote(array $extra = []): array
{
    return ['allow' => '198.51.100.7', 'duration' => 30, 'acknowledge_restart' => true, ...$extra];
}

describe('the address list', function () {
    it('accepts addresses and ranges, one per line or separated', function () {
        expect(RemoteAllowList::parse("198.51.100.7\n203.0.113.0/24, 2001:DB8:1::5"))->toBe(['198.51.100.7', '203.0.113.0/24', '2001:db8:1::5']);
        expect(RemoteAllowList::parse(['198.51.100.7', '198.51.100.7']))->toBe(['198.51.100.7']);
    });

    it('never accepts everybody, wide ranges, private addresses or nothing', function (string $entry) {
        expect(fn () => RemoteAllowList::parse($entry))->toThrow(DatabaseException::class);
    })->with(['', '0.0.0.0/0', '::/0', '198.51.0.0/15', '2001:db8::/32', '10.0.0.5', '192.168.1.10', '127.0.0.1', 'not-an-ip', '1.2.3.4/33', '1.2.3.4/x', '1.2.3.4/24/8']);

    it('allows at most twenty', function () {
        $many = implode(' ', array_map(fn ($i) => "198.51.100.{$i}", range(1, 21)));

        expect(fn () => RemoteAllowList::parse($many))->toThrow(DatabaseException::class);
    });
});

describe('opening', function () {
    it('needs its own permission and a recent password', function () {
        $this->post('/databases/main/remote', openRemote())->assertRedirect(); // password confirmation first

        expect($this->server->fresh()->remoteOpen())->toBeFalse();
    });

    it('opens a random port with TLS for the listed addresses, then closes at the end time', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote(['allow' => "198.51.100.7\n203.0.113.0/24"]))->assertSessionHasNoErrors();

        $server = $this->server->fresh();
        [$first, $last] = RemoteAccess::range();

        expect($server->remote_status)->toBe('open')
            ->and($server->remote_port)->toBeBetween($first, $last)
            ->and($server->tls_enabled)->toBeTrue()
            ->and($server->tls_ca)->toContain('BEGIN CERTIFICATE')
            ->and($server->remote_allow)->toBe(['198.51.100.7', '203.0.113.0/24'])
            ->and($server->remote_expires_at->diffInMinutes(now(), true))->toBeLessThanOrEqual(30);

        $listener = $this->host->databaseRemoteListeners()[0];
        expect($listener->port)->toBe($server->remote_port)
            ->and($listener->allow)->toBe(['198.51.100.7', '203.0.113.0/24'])
            ->and($listener->expiresAt)->toBe($server->remote_expires_at->getTimestamp());

        // The server was started again with TLS and the container was told to use the certificate.
        $specs = collect($this->host->actions())->where('action', 'runContainer')->map(fn ($c) => $c['args']['spec']);
        expect($specs->last()->databaseTls)->toBeTrue()->and($specs->first()->databaseTls)->toBeFalse();

        $this->travel(31)->minutes();
        $this->artisan('commypanel:tick-remote-access')->assertSuccessful();

        expect($this->server->fresh()->remoteOpen())->toBeFalse()
            ->and($this->host->databaseRemoteListeners())->toBe([])
            ->and(AuditLog::query()->where('action', 'database.remote_expired')->exists())->toBeTrue();
    });

    it('can stay open without an end only when the person confirms', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote(['never' => true]))->assertSessionHasErrors('acknowledge');
        expect($this->server->fresh()->remoteOpen())->toBeFalse();

        ($this->confirmed)()->post('/databases/main/remote', openRemote(['never' => true, 'acknowledge' => true]))->assertSessionHasNoErrors();

        $server = $this->server->fresh();
        expect($server->remote_forever)->toBeTrue()->and($server->remote_expires_at)->toBeNull()
            ->and($this->host->databaseRemoteListeners()[0]->expiresAt)->toBe(0);

        $this->travel(400)->days();
        $this->artisan('commypanel:tick-remote-access');
        expect($this->server->fresh()->remoteOpen())->toBeTrue();
    });

    it('refuses without a list, with a duration that is not offered, or before the server address is known', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote(['allow' => '']))->assertSessionHasErrors('allow');
        ($this->confirmed)()->post('/databases/main/remote', openRemote(['duration' => 7]))->assertSessionHasErrors('remote');
        ($this->confirmed)()->post('/databases/main/remote', ['allow' => '198.51.100.7'])->assertSessionHasErrors('duration');

        app(ServerAddress::class)->save(null, null);
        ($this->confirmed)()->post('/databases/main/remote', openRemote())->assertSessionHasErrors('remote');

        expect($this->server->fresh()->remoteOpen())->toBeFalse();
    });

    it('is refused while the server is not running', function () {
        $this->server->forceFill(['status' => 'stopped'])->save();

        ($this->confirmed)()->post('/databases/main/remote', openRemote())->assertSessionHasErrors('remote');
    });

    it('picks another port when the first is taken, and gives up cleanly', function () {
        $this->host->setBusyRemotePorts(range(...RemoteAccess::range()));

        ($this->confirmed)()->post('/databases/main/remote', openRemote());

        $server = $this->server->fresh();
        expect($server->remote_status)->toBe('failed')->and($server->remote_error)->toContain('port')
            ->and($this->host->databaseRemoteListeners())->toBe([])
            ->and(AuditLog::query()->where('action', 'database.remote_failed')->exists())->toBeTrue();
    });

    it('keeps its certificate and does not restart the server a second time', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote());
        $runs = collect($this->host->actions())->where('action', 'runContainer')->count();

        ($this->confirmed)()->delete('/databases/main/remote');
        ($this->confirmed)()->post('/databases/main/remote', openRemote());

        expect(collect($this->host->actions())->where('action', 'runContainer')->count())->toBe($runs)->and($this->server->fresh()->remote_status)->toBe('open');
    });
});

it('asks before the first restart, but not again once TLS is on', function () {
    ($this->confirmed)()->post('/databases/main/remote', ['allow' => '198.51.100.7', 'duration' => 30])->assertSessionHasErrors('acknowledge_restart');
    expect($this->server->fresh()->remoteOpen())->toBeFalse();

    ($this->confirmed)()->post('/databases/main/remote', openRemote());
    ($this->confirmed)()->delete('/databases/main/remote');
    ($this->confirmed)()->post('/databases/main/remote', ['allow' => '198.51.100.7', 'duration' => 30])->assertSessionHasNoErrors();

    expect($this->server->fresh()->remote_status)->toBe('open');
});

describe('ports that must not collide', function () {
    it('keeps the public range apart from everything else the server listens on', function () {
        [$first, $last] = RemoteAccess::range();
        $others = [
            'apps' => [(int) config('commypanel.deploy.port_range_start'), (int) config('commypanel.deploy.port_range_end')],
            'database tools' => [29000, 29099],
            'linux outgoing' => [32768, 60999],
        ];

        foreach ($others as $name => [$from, $to]) {
            expect($last < $from || $first > $to)->toBeTrue("the public range overlaps {$name}");
        }

        foreach ([22, 80, 443, 2053, 3306, 5432, 8080, 8081, 8443] as $port) {
            expect($port >= $first && $port <= $last)->toBeFalse("port {$port} is inside the public range");
        }
    });

    it('never picks a port inside a moved app range', function () {
        [$first, $last] = RemoteAccess::range();
        config(['commypanel.deploy.port_range_start' => $first, 'commypanel.deploy.port_range_end' => $last - 5]);

        for ($i = 0; $i < 5; $i++) {
            ($this->confirmed)()->post('/databases/main/remote', openRemote());
            expect($this->server->fresh()->remote_port)->toBeGreaterThan($last - 5);
            ($this->confirmed)()->delete('/databases/main/remote');
        }
    });
});

describe('changing and closing', function () {
    beforeEach(function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote());
    });

    it('changes the list and the time but keeps the port', function () {
        $port = $this->server->fresh()->remote_port;

        ($this->confirmed)()->put('/databases/main/remote', ['allow' => '203.0.113.50', 'duration' => 240])->assertSessionHasNoErrors();

        $server = $this->server->fresh();
        expect($server->remote_port)->toBe($port)->and($server->remote_allow)->toBe(['203.0.113.50'])
            ->and($this->host->databaseRemoteListeners()[0]->allow)->toBe(['203.0.113.50']);

        $entry = AuditLog::query()->where('action', 'database.remote_changed')->firstOrFail();
        expect($entry->metadata['before']['allow'])->toBe(['198.51.100.7']);
    });

    it('closes it now', function () {
        ($this->confirmed)()->delete('/databases/main/remote')->assertSessionHasNoErrors();

        expect($this->server->fresh()->remoteOpen())->toBeFalse()->and($this->host->databaseRemoteListeners())->toBe([])
            ->and(AuditLog::query()->where('action', 'database.remote_closed')->exists())->toBeTrue();
    });

    it('opens the port again when the agent lost it, and closes ones the panel does not know', function () {
        $this->host->loseRemoteListeners();
        $this->artisan('commypanel:tick-remote-access');
        expect($this->host->databaseRemoteListeners())->toHaveCount(1);

        $this->host->openDatabaseRemote('ghost', 'postgres', 25000, ['198.51.100.7'], 0);
        $this->artisan('commypanel:tick-remote-access');
        expect(collect($this->host->databaseRemoteListeners())->pluck('server')->all())->toBe(['main']);
    });

    it('closes the port before a server is deleted', function () {
        app(DatabaseServers::class)->delete($this->server->fresh());

        expect($this->host->databaseRemoteListeners())->toBe([]);
    });
});

describe('what people see', function () {
    it('shows the state and the certificate download, never the keys', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote());

        $this->get('/databases/main')->assertInertia(fn (Assert $page) => $page
            ->where('remote.status', 'open')
            ->where('remote.allow', ['198.51.100.7'])
            ->where('remote.host', '203.0.113.9')
            ->where('remote.tls', true)
            ->where('remote_durations', RemoteAccess::DURATIONS)
            ->where('remote_ports', RemoteAccess::range()));

        ($this->confirmed)()->get('/databases/main/remote/ca')->assertOk()->assertHeader('Content-Type', 'application/x-pem-file');
    });

    it('has no certificate to give before the first opening', function () {
        ($this->confirmed)()->get('/databases/main/remote/ca')->assertNotFound();
    });
});

describe('the audit log colours', function () {
    it('grades what matters', function () {
        expect(Severity::of('database.remote_opened'))->toBe('danger')
            ->and(Severity::of('database_server.deleted'))->toBe('danger')
            ->and(Severity::of('database.credentials_viewed'))->toBe('warning')
            ->and(Severity::of('deploy.succeeded'))->toBe('info')
            ->and(Severity::of('something.new'))->toBe('info');
    });

    it('sends the grade with every entry', function () {
        ($this->confirmed)()->post('/databases/main/remote', openRemote());

        $this->get('/settings/audit-log')->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.action', 'database.remote_opened')
            ->where('logs.data.0.severity', 'danger'));
    });
});

it('is the only way a server was reached: a database nobody opened has no public port', function () {
    expect($this->server->remoteOpen())->toBeFalse()->and($this->host->databaseRemoteListeners())->toBe([]);
});

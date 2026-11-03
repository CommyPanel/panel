<?php

use App\Dns\ServerAddress;
use App\Host\Data\PublicAddresses;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\DetectServerAddressJob;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Cache::flush();
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
});

it('finds the address on the server\'s own interfaces without asking anyone', function () {
    config(['commypanel.address_lookup' => true]);
    Http::fake();
    $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20'], ['2a01:4f9::1'], '65.21.10.20', '2a01:4f9::1'));

    expect(app(ServerAddress::class)->detect())->toBe(['ipv4' => '65.21.10.20', 'ipv6' => '2a01:4f9::1', 'via' => 'interface']);

    Http::assertNothingSent();
});

it('prefers the address the server reaches the internet from, and takes the first otherwise', function () {
    $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20', '65.21.10.21'], [], '65.21.10.21'));
    expect(app(ServerAddress::class)->detect()['ipv4'])->toBe('65.21.10.21');

    $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20', '65.21.10.21']));
    expect(app(ServerAddress::class)->detect()['ipv4'])->toBe('65.21.10.20');
});

it('does not look for an IPv6 address elsewhere when the server has an IPv4 one and no IPv6', function () {
    config(['commypanel.address_lookup' => true]);
    Http::fake();
    $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20']));

    expect(app(ServerAddress::class)->detect())->toBe(['ipv4' => '65.21.10.20', 'ipv6' => null, 'via' => 'interface']);

    Http::assertNothingSent();
});

describe('behind a NAT (no public address on the interfaces)', function () {
    it('asks api.ipify.org when that is switched on', function () {
        config(['commypanel.address_lookup' => true]);
        Http::fake(['https://api.ipify.org' => Http::response("203.0.113.77\n"), 'https://api6.ipify.org' => Http::response('', 500)]);

        expect(app(ServerAddress::class)->detect())->toBe(['ipv4' => '203.0.113.77', 'ipv6' => null, 'via' => 'lookup']);
    });

    it('never asks when the lookup is switched off', function () {
        config(['commypanel.address_lookup' => false]);
        Http::fake();

        expect(app(ServerAddress::class)->detect())->toBe(['ipv4' => null, 'ipv6' => null, 'via' => null]);

        Http::assertNothingSent();
    });

    it('does not accept an answer that is not an address', function () {
        config(['commypanel.address_lookup' => true]);
        Http::fake(['*' => Http::response('<html>captive portal</html>')]);

        expect(app(ServerAddress::class)->detect()['ipv4'])->toBeNull();
    });
});

it('looks nothing up when the agent cannot be asked', function () {
    config(['commypanel.address_lookup' => true]);
    Http::fake();
    app()->instance(HostDriver::class, new class extends FakeHostDriver
    {
        public function publicAddresses(): PublicAddresses
        {
            throw new HostOperationFailed('The CommyPanel agent is not reachable.');
        }
    });

    expect(app(ServerAddress::class)->detect()['via'])->toBeNull();

    Http::assertNothingSent();
});

describe('on its own, in the background', function () {
    it('is found the first time a page needs it, and saved as detected', function () {
        $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20'], [], '65.21.10.20'));

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('server.ipv4', '65.21.10.20')
            ->where('server.source', 'auto')
            ->where('server.detecting', false)
            ->where('server.failed', false));

        expect(AuditLog::query()->where('action', 'server.address_detected')->exists())->toBeTrue();
    });

    it('is shown as failed when nothing can be found, and not tried again at once', function () {
        $calls = 0;
        app()->instance(HostDriver::class, new class($calls) extends FakeHostDriver
        {
            public function __construct(private int &$calls) {}

            public function publicAddresses(): PublicAddresses
            {
                $this->calls++;

                return new PublicAddresses;
            }
        });

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('server.ipv4', null)
            ->where('server.source', null)
            ->where('server.failed', true)
            ->where('server.detecting', false));
        $this->get('/domains');
        $this->get('/domains');

        expect($calls)->toBe(1);

        $this->travel(11)->minutes();
        $this->get('/domains');

        expect($calls)->toBe(2);
    });

    it('is shown as detecting while the queue has not got to it', function () {
        Queue::fake();

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('server.detecting', true)
            ->where('server.failed', false));

        // Not queued a second time while it is on its way.
        $this->get('/domains');
        Queue::assertPushed(DetectServerAddressJob::class, 1);

        // A queue that never answers is not waited for forever.
        $this->travel(3)->minutes();
        $this->get('/domains');
        Queue::assertPushed(DetectServerAddressJob::class, 2);
    });

    it('never replaces what a person set', function () {
        app(ServerAddress::class)->save('198.51.100.9', null, 'manual');
        $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20'], [], '65.21.10.20'));

        app(ServerAddress::class)->runDetection();
        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('server.ipv4', '198.51.100.9')
            ->where('server.source', 'manual'));
    });

    it('is the same on every page that shows the address', function (string $url) {
        $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20'], [], '65.21.10.20'));

        $this->get($url)->assertInertia(fn (Assert $page) => $page->where('server.ipv4', '65.21.10.20')->where('server.source', 'auto'));
    })->with(['/domains', '/welcome']);
});

describe('correcting it', function () {
    it('marks what a person saved as theirs, and records what it was before', function () {
        $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.20'], [], '65.21.10.20'));
        $this->get('/domains');

        $this->put('/settings/server-address', ['ipv4' => '198.51.100.9', 'ipv6' => ''])->assertSessionHasNoErrors();

        $server = app(ServerAddress::class);
        expect($server->ipv4())->toBe('198.51.100.9')->and($server->source())->toBe('manual');

        $entry = AuditLog::query()->where('action', 'server.address_set')->first();
        expect($entry->metadata['before'])->toBe(['ipv4' => '65.21.10.20', 'ipv6' => null, 'source' => 'auto']);
    });

    it('can be detected again by hand, and says when nothing is found', function () {
        $this->host->setPublicAddresses(new PublicAddresses(['65.21.10.30'], [], '65.21.10.30'));
        app(ServerAddress::class)->save('198.51.100.9', null, 'manual');

        $this->post('/settings/server-address/detect')->assertSessionHas('status');
        expect(app(ServerAddress::class)->ipv4())->toBe('65.21.10.30')->and(app(ServerAddress::class)->source())->toBe('auto');

        $this->host->setPublicAddresses(new PublicAddresses);
        $this->post('/settings/server-address/detect')->assertSessionHasErrors('ipv4');
        expect(app(ServerAddress::class)->ipv4())->toBe('65.21.10.30');
    });
});

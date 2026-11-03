<?php

use App\Dns\DnsResolver;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Enums\CertificateStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Host\Data\NginxSite;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsResolver;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    app(ServerAddress::class)->save('203.0.113.7', null);
    $this->dns = new FakeDnsResolver(['shop.example.com' => ['a' => ['203.0.113.7']]]);
    app()->instance(DnsResolver::class, $this->dns);
    $this->host = app(HostDriver::class);
});

function liveApp(string $slug = 'shop', int $port = 20001): App
{
    $app = App::factory()->create(['slug' => $slug]);
    $deployment = Deployment::factory()->create([
        'app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => $port,
    ]);
    $app->update(['active_deployment_id' => $deployment->id]);

    return $app;
}

it('requires login', function () {
    auth()->logout();

    $this->get('/domains')->assertRedirect('/login');
    $this->post('/apps/shop/domains', ['name' => 'a.example.com'])->assertRedirect('/login');
});

it('lists domains with status, problems and the server address', function () {
    Domain::query()->create(['name' => 'shop.example.com', 'status' => DomainStatus::Verified]);
    Domain::query()->create(['name' => 'old.example.com', 'status' => DomainStatus::Misconfigured, 'last_check' => ['a' => ['198.51.100.9'], 'aaaa' => [], 'problems' => ['Wrong IP']]]);
    App::factory()->create(['slug' => 'shop', 'name' => 'Shop']);

    $this->get('/domains')->assertInertia(fn (Assert $page) => $page
        ->component('domains/index')
        ->where('server.ipv4', '203.0.113.7')
        ->where('server.ipv6', null)
        ->has('domains', 2)
        ->where('domains.0.name', 'old.example.com')
        ->where('domains.0.status', 'misconfigured')
        ->where('domains.0.problems', ['Wrong IP'])
        ->where('domains.1.status_label', 'connected'));
});

it('adds a domain to an app, normalises it and checks DNS straight away', function () {
    App::factory()->create(['slug' => 'shop']);

    $this->post('/apps/shop/domains', ['name' => '  Shop.Example.COM. ', 'redirect' => 'none'])->assertSessionHasNoErrors();

    $domain = Domain::query()->firstOrFail();
    expect($domain->name)->toBe('shop.example.com')
        ->and($domain->app->slug)->toBe('shop')
        ->and($domain->status)->toBe(DomainStatus::Verified)
        ->and($domain->verified_at)->not->toBeNull()
        ->and(AuditLog::query()->pluck('action')->all())->toContain('domain.added', 'domain.verified');
});

it('rejects invalid, duplicate and panel domains', function (string $input) {
    App::factory()->create(['slug' => 'shop']);
    $other = App::factory()->create();
    Domain::query()->create(['name' => 'taken.example.com', 'app_id' => $other->id]);
    app(PanelDomain::class)->set('panel.example.com');

    $this->post('/apps/shop/domains', ['name' => $input, 'redirect' => 'none'])->assertSessionHasErrors('name');

    expect(Domain::query()->count())->toBe(1);
})->with(['https://x.example.com', '*.example.com', 'localhost', '203.0.113.7', 'taken.example.com', 'TAKEN.example.com', 'panel.example.com']);

it('routes a new domain at once when the app is live', function () {
    liveApp();

    $this->post('/apps/shop/domains', ['name' => 'shop.example.com', 'redirect' => 'none'])->assertSessionHasNoErrors();

    $site = $this->host->sites()['shop'];
    expect($site->domains)->toBe(['shop.example.com'])
        ->and($site->upstreamPort)->toBe(20001);
});

it('puts every domain of an app into its nginx site', function () {
    $app = liveApp();
    Domain::query()->create(['name' => 'b.example.com', 'app_id' => $app->id]);

    $this->post('/apps/shop/domains', ['name' => 'a.example.com', 'redirect' => 'none']);

    expect($this->host->sites()['shop']->domains)->toBe(['a.example.com', 'b.example.com']);
});

it('adds no nginx site for an app that is not live yet', function () {
    App::factory()->create(['slug' => 'shop']);

    $this->post('/apps/shop/domains', ['name' => 'shop.example.com', 'redirect' => 'none']);

    expect($this->host->sites())->toBe([])
        ->and(Domain::query()->first()->app_id)->not->toBeNull();
});

describe('redirects', function () {
    it('adds the www name and redirects the bare domain to it', function () {
        liveApp();

        $this->post('/apps/shop/domains', ['name' => 'example.com', 'redirect' => 'www'])->assertSessionHasNoErrors();

        expect(Domain::query()->orderBy('name')->pluck('redirect_to', 'name')->all())
            ->toBe(['example.com' => 'www.example.com', 'www.example.com' => null])
            ->and($this->host->sites()['shop']->redirects)->toBe(['example.com' => 'www.example.com'])
            ->and($this->host->sites()['shop']->domains)->toBe(['example.com', 'www.example.com']);
    });

    it('adds the bare domain and redirects www to it', function () {
        liveApp();

        $this->post('/apps/shop/domains', ['name' => 'www.example.com', 'redirect' => 'apex'])->assertSessionHasNoErrors();

        expect($this->host->sites()['shop']->redirects)->toBe(['www.example.com' => 'example.com']);
    });

    it('refuses a redirect that does not fit the name', function (string $name, string $mode) {
        App::factory()->create(['slug' => 'shop']);

        $this->post('/apps/shop/domains', ['name' => $name, 'redirect' => $mode])->assertSessionHasErrors('redirect');

        expect(Domain::query()->count())->toBe(0);
    })->with([['www.example.com', 'www'], ['example.com', 'apex']]);

    it('refuses when the partner name is already used', function () {
        $other = App::factory()->create();
        Domain::query()->create(['name' => 'www.example.com', 'app_id' => $other->id]);
        App::factory()->create(['slug' => 'shop']);

        $this->post('/apps/shop/domains', ['name' => 'example.com', 'redirect' => 'www'])->assertSessionHasErrors('name');

        expect(Domain::query()->count())->toBe(1);
    });

    it('lets the redirected name serve again once its target is removed', function () {
        $app = liveApp();
        $this->post('/apps/shop/domains', ['name' => 'example.com', 'redirect' => 'www']);
        $target = Domain::query()->where('name', 'www.example.com')->firstOrFail();

        $this->delete("/domains/{$target->id}");

        expect(Domain::query()->where('name', 'example.com')->first()->redirect_to)->toBeNull()
            ->and($this->host->sites()['shop']->redirects)->toBe([])
            ->and($app->domains()->count())->toBe(1);
    });

    it('is not offered on the global domains page any more', function () {
        $this->post('/domains', ['name' => 'a.example.com'])->assertStatus(405);
    });
});

it('removes a domain and updates nginx', function () {
    $app = liveApp();
    $domain = Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $app->id]);
    $this->host->applyNginxSite(new NginxSite('shop', ['shop.example.com'], 20001));

    $this->delete("/domains/{$domain->id}")->assertSessionHasNoErrors();

    expect(Domain::query()->count())->toBe(0)
        ->and($this->host->sites())->toBe([]);
});

it('re-checks a domain on request and records the outcome', function () {
    $domain = Domain::query()->create(['name' => 'later.example.com']);

    $this->post("/domains/{$domain->id}/check");
    expect($domain->fresh()->status)->toBe(DomainStatus::Pending);

    $this->dns->records['later.example.com'] = ['a' => ['203.0.113.7']];
    $this->post("/domains/{$domain->id}/check");

    expect($domain->fresh()->status)->toBe(DomainStatus::Verified);
});

it('keeps domains but unassigns them when an app is deleted', function () {
    $app = liveApp();
    $domain = Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $app->id]);

    $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

    expect($domain->fresh()->app_id)->toBeNull()
        ->and(collect($this->host->actions())->where('action', 'removeNginxSite')->pluck('args.name')->all())->toContain('shop');
});

it('shows an app its domains', function () {
    $app = liveApp();
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $app->id, 'status' => DomainStatus::Verified]);

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
        ->where('app.domains.0.name', 'shop.example.com')
        ->where('app.domains.0.status', 'verified')
        ->where('server.ipv4', '203.0.113.7'));
});

describe('details', function () {
    it('shows one domain with its DNS findings, app and certificate state', function () {
        $app = liveApp();
        $domain = Domain::query()->create([
            'name' => 'shop.example.com', 'app_id' => $app->id, 'status' => DomainStatus::Misconfigured,
            'last_check' => ['a' => ['198.51.100.9'], 'aaaa' => [], 'problems' => ['Wrong IP']],
        ]);
        Domain::query()->create(['name' => 'example.com', 'app_id' => $app->id, 'redirect_to' => 'shop.example.com']);
        Certificate::query()->create([
            'app_id' => $app->id, 'status' => CertificateStatus::Active, 'domains' => ['shop.example.com'],
            'issued_at' => now()->subDay(), 'expires_at' => now()->addDays(60),
        ]);

        $this->get("/domains/{$domain->id}")->assertInertia(fn (Assert $page) => $page
            ->component('domains/show')
            ->where('domain.name', 'shop.example.com')
            ->where('domain.app', 'shop')
            ->where('domain.tls', 'secured')
            ->where('domain.found.a', ['198.51.100.9'])
            ->where('domain.problems', ['Wrong IP'])
            ->where('certificate.covers_domain', true)
            ->where('redirected_from', ['example.com'])
            ->where('server.ipv4', '203.0.113.7'));
    });

    it('reports HTTPS as none for a domain that is not on the certificate', function () {
        $app = liveApp();
        Domain::query()->create(['name' => 'new.example.com', 'app_id' => $app->id]);
        Certificate::query()->create([
            'app_id' => $app->id, 'status' => CertificateStatus::Active, 'domains' => ['shop.example.com'],
            'issued_at' => now()->subDay(), 'expires_at' => now()->addDays(60),
        ]);

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('domains.0.tls', 'none'));
    });

    it('lists the panel domain next to the others', function () {
        app(PanelDomain::class)->set('panel.example.com');

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('panel.domain', 'panel.example.com')
            ->where('panel.certificate', 'none'));
    });

    it('returns to the list after removing a domain from its own page', function () {
        $domain = Domain::query()->create(['name' => 'gone.example.com']);

        $this->delete("/domains/{$domain->id}", ['return' => 'domains'])->assertRedirect('/domains');

        expect(Domain::query()->count())->toBe(0);
    });

    it('requires login', function () {
        $domain = Domain::query()->create(['name' => 'a.example.com']);
        auth()->logout();

        $this->get("/domains/{$domain->id}")->assertRedirect('/login');
    });
});

describe('server address', function () {
    it('saves addresses and validates them', function () {
        $this->put('/settings/server-address', ['ipv4' => '198.51.100.20', 'ipv6' => '2001:db8::20'])->assertSessionHasNoErrors();

        expect(app(ServerAddress::class)->ipv4())->toBe('198.51.100.20')
            ->and(app(ServerAddress::class)->ipv6())->toBe('2001:db8::20');

        $this->put('/settings/server-address', ['ipv4' => 'not-an-ip', 'ipv6' => ''])->assertSessionHasErrors('ipv4');
        $this->put('/settings/server-address', ['ipv4' => '2001:db8::1', 'ipv6' => ''])->assertSessionHasErrors('ipv4');
        $this->put('/settings/server-address', ['ipv4' => '', 'ipv6' => ''])->assertSessionHasErrors('ipv4');
    });

    it('can clear IPv6 while keeping IPv4', function () {
        app(ServerAddress::class)->save('198.51.100.20', '2001:db8::20');

        $this->put('/settings/server-address', ['ipv4' => '198.51.100.20', 'ipv6' => ''])->assertSessionHasNoErrors();

        expect(app(ServerAddress::class)->ipv6())->toBeNull();
    });

    it('can be detected again by hand, through the outside lookup behind a NAT', function () {
        config(['commypanel.address_lookup' => true]);
        app(ServerAddress::class)->save('198.51.100.1', null, 'manual');
        Http::fake([
            'api.ipify.org' => Http::response('198.51.100.20'),
            'api6.ipify.org' => Http::response('2001:db8::20'),
        ]);

        $this->post('/settings/server-address/detect')->assertSessionHasNoErrors();

        expect(app(ServerAddress::class)->ipv4())->toBe('198.51.100.20')
            ->and(app(ServerAddress::class)->ipv6())->toBe('2001:db8::20');
    });

    it('keeps existing values when detection finds nothing for a family', function () {
        config(['commypanel.address_lookup' => true]);
        app(ServerAddress::class)->save('198.51.100.1', '2001:db8::1');
        Http::fake([
            'api.ipify.org' => Http::response('198.51.100.20'),
            'api6.ipify.org' => Http::response('', 500),
        ]);

        $this->post('/settings/server-address/detect');

        expect(app(ServerAddress::class)->ipv4())->toBe('198.51.100.20')
            ->and(app(ServerAddress::class)->ipv6())->toBe('2001:db8::1');
    });

    it('reports failure and ignores garbage answers', function () {
        config(['commypanel.address_lookup' => true]);
        app(ServerAddress::class)->save(null, null);
        Http::fake(['*' => Http::response('<html>nope</html>')]);

        $this->post('/settings/server-address/detect')->assertSessionHasErrors('ipv4');

        expect(app(ServerAddress::class)->isConfigured())->toBeFalse();
    });
});

describe('scheduled check', function () {
    it('re-checks pending domains and connected ones only once they are stale', function () {
        $pending = Domain::query()->create(['name' => 'shop.example.com', 'status' => DomainStatus::Pending, 'last_checked_at' => now()]);
        $freshOk = Domain::query()->create(['name' => 'fresh.example.com', 'status' => DomainStatus::Verified, 'last_checked_at' => now()->subMinutes(10)]);
        $staleOk = Domain::query()->create(['name' => 'stale.example.com', 'status' => DomainStatus::Verified, 'last_checked_at' => now()->subHours(2)]);

        $this->artisan('commypanel:check-domains')->assertSuccessful();

        expect($pending->fresh()->status)->toBe(DomainStatus::Verified)
            ->and($freshOk->fresh()->last_checked_at->diffInMinutes(now()))->toBeGreaterThan(9)
            ->and($staleOk->fresh()->status)->toBe(DomainStatus::Pending);
    });
});

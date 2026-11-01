<?php

use App\Dns\DnsResolver;
use App\Dns\PanelDomain;
use App\Dns\ServerAddress;
use App\Enums\DomainStatus;
use App\Host\Data\CertificateInfo;
use App\Host\Data\CertificateRequest;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\IssuePanelCertificateJob;
use App\Models\App;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Tls\HttpsSettings;
use App\Tls\IssuePanelCertificate;
use App\Tls\PanelRouting;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsResolver;

beforeEach(function () {
    config(['commypanel.https.staging' => true]);
    $this->actingAs(User::factory()->create());
    app(ServerAddress::class)->save('203.0.113.7', null);
    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);
    $this->host = app(HostDriver::class);
    $this->panel = app(PanelDomain::class);
});

it('requires login', function () {
    auth()->logout();

    $this->get('/welcome')->assertRedirect('/login');
    $this->post('/welcome/domain', ['domain' => 'panel.example.com'])->assertRedirect('/login');
});

it('starts on the Let\'s Encrypt step', function () {
    $this->get('/welcome')->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('https.enabled', false)
        ->where('panel.domain', null)
        ->where('panel.certificate.status', 'none'));
});

describe('terms', function () {
    it('needs the agreement to be accepted', function () {
        $this->post('/welcome/terms', ['agree' => false])->assertSessionHasErrors('agree');

        expect(app(HttpsSettings::class)->enabled())->toBeFalse();
    });

    it('turns HTTPS on with an optional email', function () {
        $this->post('/welcome/terms', ['agree' => true, 'email' => 'ops@example.com'])->assertRedirect('/welcome');

        expect(app(HttpsSettings::class)->enabled())->toBeTrue()
            ->and(app(HttpsSettings::class)->email())->toBe('ops@example.com');
    });

    it('rejects a bad email', function () {
        $this->post('/welcome/terms', ['agree' => true, 'email' => 'nope'])->assertSessionHasErrors('email');
    });
});

describe('panel domain', function () {
    it('rejects names that are not plain domains and names used by an app', function (string $input) {
        $app = App::factory()->create();
        Domain::query()->create(['name' => 'taken.example.com', 'app_id' => $app->id]);

        $this->post('/welcome/domain', ['domain' => $input])->assertSessionHasErrors('domain');

        expect($this->panel->name())->toBeNull();
    })->with(['https://x.example.com', '*.example.com', 'localhost', '203.0.113.7', 'taken.example.com']);

    it('saves the domain and checks its DNS straight away', function () {
        $this->post('/welcome/domain', ['domain' => '  Panel.Example.COM. '])->assertRedirect('/welcome');

        expect($this->panel->name())->toBe('panel.example.com')
            ->and($this->panel->dns()['status'])->toBe(DomainStatus::Pending->value)
            ->and($this->panel->dnsVerified())->toBeFalse();
    });

    it('requests the certificate once DNS is right and HTTPS is on', function () {
        Queue::fake();
        app(HttpsSettings::class)->enable(null);
        $this->post('/welcome/domain', ['domain' => 'panel.example.com']);
        Queue::assertNothingPushed();

        $this->dns->records['panel.example.com'] = ['a' => ['203.0.113.7']];
        $this->post('/welcome/domain/check')->assertRedirect('/welcome');

        expect($this->panel->dnsVerified())->toBeTrue();
        Queue::assertPushed(IssuePanelCertificateJob::class, 1);
    });

    it('does not request a certificate before the terms are accepted', function () {
        Queue::fake();
        $this->dns->records['panel.example.com'] = ['a' => ['203.0.113.7']];

        $this->post('/welcome/domain', ['domain' => 'panel.example.com']);

        expect($this->panel->dnsVerified())->toBeTrue();
        Queue::assertNothingPushed();

        $this->post('/welcome/terms', ['agree' => true]);
        Queue::assertPushed(IssuePanelCertificateJob::class, 1);
    });

    it('lets the user retry after a failure straight away', function () {
        Queue::fake();
        app(HttpsSettings::class)->enable(null);
        $this->dns->records['panel.example.com'] = ['a' => ['203.0.113.7']];
        $this->post('/welcome/domain', ['domain' => 'panel.example.com']);
        $this->panel->markFailed('Boom', false);
        Queue::fake();

        $this->post('/welcome/domain/check');

        Queue::assertPushed(IssuePanelCertificateJob::class, 1);
    });

    it('clears the domain and its site', function () {
        $this->panel->set('panel.example.com');

        $this->delete('/welcome/domain')->assertRedirect('/welcome');

        expect($this->panel->name())->toBeNull();
    });
});

describe('certificate', function () {
    beforeEach(function () {
        app(HttpsSettings::class)->enable('ops@example.com');
        $this->dns->records['panel.example.com'] = ['a' => ['203.0.113.7']];
        $this->panel->set('panel.example.com');
        $this->panel->recordDns('verified', []);
    });

    it('issues it and serves the panel on its domain through nginx', function () {
        app(IssuePanelCertificate::class)();

        $site = $this->host->sites()['panel'];
        expect($this->panel->certificateUsable())->toBeTrue()
            ->and($site->domains)->toBe(['panel.example.com'])
            ->and($site->certificate)->toBe('panel')
            ->and($site->upstreamPort)->toBe((int) config('commypanel.panel_internal_port'));

        $this->get('/welcome')->assertInertia(fn (Assert $page) => $page
            ->where('panel.certificate.status', 'active')
            ->where('panel.url', 'https://panel.example.com'));
    });

    it('is written again by the post-upgrade command', function () {
        app(IssuePanelCertificate::class)();
        $before = count(array_keys($this->host->actionNames(), 'applyNginxSite'));

        $this->artisan('commypanel:sync-panel-site')->assertSuccessful();

        expect(count(array_keys($this->host->actionNames(), 'applyNginxSite')))->toBe($before + 1)
            ->and($this->host->sites())->toHaveKey('panel');
    });

    it('is not served over plain HTTP before the certificate exists', function () {
        app(PanelRouting::class)->sync();

        expect($this->host->sites())->not->toHaveKey('panel');
    });

    it('records a failure and keeps serving a certificate that is still valid', function () {
        app(IssuePanelCertificate::class)();

        $host = new class extends FakeHostDriver
        {
            public function issueCertificate(CertificateRequest $request): CertificateInfo
            {
                throw new HostOperationFailed('Rate limited');
            }
        };
        app()->instance(HostDriver::class, $host);
        app()->forgetInstance(IssuePanelCertificate::class);

        app(IssuePanelCertificate::class)();

        expect($this->panel->certificateStatus())->toBe('active')
            ->and($this->panel->certificateError())->toBe('Rate limited');
    });

    it('marks a first failure as failed', function () {
        $host = new class extends FakeHostDriver
        {
            public function issueCertificate(CertificateRequest $request): CertificateInfo
            {
                throw new HostOperationFailed('DNS problem');
            }
        };
        app()->instance(HostDriver::class, $host);
        app()->forgetInstance(IssuePanelCertificate::class);

        app(IssuePanelCertificate::class)();

        expect($this->panel->certificateStatus())->toBe('failed')
            ->and($host->sites())->toBe([]);
    });

    it('renews through the scheduled command when it is about to expire', function () {
        Queue::fake();
        app(IssuePanelCertificate::class)();
        $this->artisan('commypanel:renew-certificates')->assertSuccessful();
        Queue::assertNothingPushed();

        Setting::write('panel.cert_expires_at', now()->addDays(5)->toIso8601String());
        $this->artisan('commypanel:renew-certificates')->assertSuccessful();

        Queue::assertPushed(IssuePanelCertificateJob::class, 1);
    });
});

it('keeps apps from taking the panel name', function () {
    expect(App::generateSlug('Panel'))->toBe('panel-app');
});

it('does not let an app use the panel domain', function () {
    app(PanelDomain::class)->set('panel.example.com');
    $app = App::factory()->create(['slug' => 'shop']);

    $this->post('/apps/shop/domains', ['name' => 'panel.example.com', 'redirect' => 'none'])->assertSessionHasErrors('name');

    expect($app->domains()->count())->toBe(0);
});

it('refreshes nothing, and succeeds, when the panel has no domain yet', function () {
    $this->artisan('commypanel:sync-panel-site')->assertSuccessful();

    expect(app(HostDriver::class)->sites())->not->toHaveKey('panel');
});

it('reports an agent that cannot be reached when refreshing the panel site', function () {
    app(HostDriver::class)->failOn('removeNginxSite');

    $this->artisan('commypanel:sync-panel-site')->assertFailed();
});

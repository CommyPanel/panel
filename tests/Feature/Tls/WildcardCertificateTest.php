<?php

use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Audit\Severity;
use App\Dns\AddAppDomain;
use App\Dns\CheckDomain;
use App\Dns\CloudflareSettings;
use App\Dns\DnsResolver;
use App\Dns\DomainName;
use App\Dns\ServerAddress;
use App\Enums\CertificateStatus;
use App\Enums\DeploymentStatus;
use App\Enums\DomainStatus;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\IssueAppCertificate;
use App\Jobs\SendAlertJob;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Setting;
use App\Models\User;
use App\Tls\HttpsSettings;
use App\Tls\IssueCertificate;
use App\Tls\TlsPlanner;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsResolver;

/**
 * Wildcard names (`*.example.com`): added like any domain, checked through one name below them, and
 * given a certificate through DNS: a Cloudflare token lets the agent make the TXT record, without one
 * the person creates it and every renewal asks for a new one.
 */
beforeEach(function () {
    config(['commypanel.https.staging' => true]);
    app(HttpsSettings::class)->enable('ops@example.com');
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->dns = new FakeDnsResolver;
    app()->instance(DnsResolver::class, $this->dns);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $deployment = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001]);
    $this->shop->update(['active_deployment_id' => $deployment->id]);

    $this->wildcard = fn (string $name = '*.example.com', DomainStatus $status = DomainStatus::Verified) => Domain::query()->create(['name' => $name, 'app_id' => $this->shop->id, 'status' => $status]);
    $this->issue = fn () => app(IssueCertificate::class)($this->shop->fresh());
    $this->connectCloudflare = fn () => app(CloudflareSettings::class)->save('cf_token_0123456789abcdefghij', 'example.com');
});

describe('the name', function () {
    it('accepts a wildcard only as the whole first label of a real domain', function (string $input, ?string $expected) {
        expect(DomainName::normalizeAllowingWildcard($input))->toBe($expected);
    })->with([
        ['*.example.com', '*.example.com'],
        ['*.Example.COM.', '*.example.com'],
        ['  *.shop.example.co.uk ', '*.shop.example.co.uk'],
        ['app.example.com', 'app.example.com'],
        ['*.com', null],
        ['*example.com', null],
        ['*.*.example.com', null],
        ['app.*.example.com', null],
        ['*.', null],
        ['*', null],
    ]);

    it('looks a wildcard up through one name below it', function () {
        expect(DomainName::probe('*.example.com'))->toBe('commypanel-check.example.com')
            ->and(DomainName::probe('app.example.com'))->toBe('app.example.com');
    });
});

describe('adding and checking', function () {
    beforeEach(function () {
        app(ServerAddress::class)->save('203.0.113.7', null);
    });

    it('adds a wildcard to an app without a www partner', function () {
        $domains = app(AddAppDomain::class)($this->shop, '*.Example.com', AddAppDomain::NONE);

        expect($domains)->toHaveCount(1)
            ->and($domains[0]->name)->toBe('*.example.com')
            ->and(Domain::query()->count())->toBe(1);
    });

    it('refuses a redirect on a wildcard, and a wildcard that is taken', function () {
        expect(fn () => app(AddAppDomain::class)($this->shop, '*.example.com', AddAppDomain::WWW))->toThrow(ValidationException::class);

        app(AddAppDomain::class)($this->shop, '*.example.com', AddAppDomain::NONE);
        $other = App::factory()->create();

        expect(fn () => app(AddAppDomain::class)($other, '*.example.com', AddAppDomain::NONE))->toThrow(ValidationException::class);
    });

    it('verifies a wildcard when any name below it points at this server', function () {
        $domain = ($this->wildcard)('*.example.com', DomainStatus::Pending);
        $this->dns->records['commypanel-check.example.com'] = ['a' => ['203.0.113.7']];

        app(CheckDomain::class)($domain);

        expect($domain->fresh()->status)->toBe(DomainStatus::Verified);
    });

    it('says what is missing in the words of a wildcard', function () {
        $domain = ($this->wildcard)('*.example.com', DomainStatus::Pending);

        app(CheckDomain::class)($domain);

        expect($domain->fresh()->last_check['problems'][0])->toContain('wildcard');

        $this->dns->records['commypanel-check.example.com'] = ['a' => ['198.51.100.9']];
        app(CheckDomain::class)($domain);

        expect($domain->fresh()->status)->toBe(DomainStatus::Misconfigured);
    });

    it('lets a wildcard behind Cloudflare\'s proxy get a certificate, because DNS proves it', function () {
        ($this->wildcard)('*.example.com', DomainStatus::Proxied);
        Domain::query()->create(['name' => 'plain.example.com', 'app_id' => $this->shop->id, 'status' => DomainStatus::Proxied]);

        expect(app(TlsPlanner::class)->eligibleDomains($this->shop))->toBe(['*.example.com']);
    });
});

describe('with a Cloudflare token', function () {
    it('has the agent make the TXT record: the token goes with the request and nothing is left waiting', function () {
        ($this->connectCloudflare)();
        ($this->wildcard)();
        ($this->wildcard)('example.com');

        ($this->issue)();

        $request = collect($this->host->actions())->where('action', 'issueCertificate')->last()['args']['request'];
        $cert = Certificate::query()->firstOrFail();

        expect($request->domains)->toBe(['*.example.com', 'example.com'])
            ->and($request->dns->provider)->toBe('cloudflare')
            ->and($request->dns->token)->toBe('cf_token_0123456789abcdefghij')
            ->and($cert->status)->toBe(CertificateStatus::Active)
            ->and($cert->domains)->toBe(['*.example.com', 'example.com'])
            ->and($cert->dns_pending)->toBeNull()
            ->and(collect($this->host->actions())->where('action', 'prepareDnsCertificate'))->toBeEmpty();
    });

    it('sends no token for a certificate without wildcard names', function () {
        ($this->connectCloudflare)();
        ($this->wildcard)('shop.example.com');

        ($this->issue)();

        $request = collect($this->host->actions())->where('action', 'issueCertificate')->last()['args']['request'];

        expect($request->dns)->toBeNull();
    });

    it('plans an issue when a wildcard is connected', function () {
        Queue::fake();
        ($this->connectCloudflare)();
        ($this->wildcard)();

        expect(app(TlsPlanner::class)->ensure($this->shop))->toBeTrue();
        Queue::assertPushed(IssueAppCertificate::class);
    });

    it('turns a failure into the usual failed certificate with the agent\'s sentence', function () {
        ($this->connectCloudflare)();
        ($this->wildcard)();
        $this->host->failOn('issueCertificate');

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->status)->toBe(CertificateStatus::Failed)->and($cert->last_error)->toContain('Fake failure');
    });
});

describe('without a Cloudflare token', function () {
    it('asks for the TXT record and waits', function () {
        ($this->wildcard)();

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->status)->toBe(CertificateStatus::AwaitingDns)
            ->and($cert->dns_pending['domains'])->toBe(['*.example.com'])
            ->and($cert->dns_pending['records'])->toHaveCount(1)
            ->and($cert->dns_pending['records'][0]['name'])->toBe('_acme-challenge.example.com')
            ->and(collect($this->host->actions())->where('action', 'issueCertificate'))->toBeEmpty()
            ->and(AuditLog::query()->where('action', 'tls.dns_needed')->exists())->toBeTrue();
    });

    it('does nothing while the record cannot be seen, and finishes the order once it can', function () {
        Queue::fake();
        ($this->wildcard)();
        ($this->issue)();
        $record = Certificate::query()->firstOrFail()->dns_pending['records'][0];

        expect(app(TlsPlanner::class)->ensure($this->shop))->toBeFalse();
        Queue::assertNothingPushed();

        // Only the right value counts, not any TXT record at the name.
        $this->dns->records[$record['name']] = ['txt' => ['something else']];
        expect(app(TlsPlanner::class)->needsIssue($this->shop))->toBeFalse();

        $this->dns->records[$record['name']] = ['txt' => ['something else', $record['value']]];
        expect(app(TlsPlanner::class)->ensure($this->shop))->toBeTrue();
        Queue::assertPushed(IssueAppCertificate::class);

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->status)->toBe(CertificateStatus::Active)
            ->and($cert->dns_pending)->toBeNull()
            ->and($cert->domains)->toBe(['*.example.com'])
            ->and(collect($this->host->actions())->where('action', 'completeDnsCertificate'))->toHaveCount(1);
    });

    it('asks again, with new records, when the order failed', function () {
        ($this->wildcard)();
        ($this->issue)();
        $record = Certificate::query()->firstOrFail()->dns_pending['records'][0];
        $this->dns->records[$record['name']] = ['txt' => [$record['value']]];
        $this->host->failDnsCompleteWith('Agent error: could not validate example.com: incorrect TXT record');

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->status)->toBe(CertificateStatus::Failed)
            ->and($cert->dns_pending)->toBeNull()
            ->and($cert->last_error)->toContain('incorrect TXT record');
    });

    it('starts over when the set of names changes', function () {
        ($this->wildcard)();
        ($this->issue)();
        ($this->wildcard)('*.other.example.com');

        expect(app(TlsPlanner::class)->needsIssue($this->shop))->toBeTrue();

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->dns_pending['domains'])->toBe(['*.example.com', '*.other.example.com'])
            ->and($cert->dns_pending['records'])->toHaveCount(2);
    });

    it('asks for a new record at renewal, and the certificate in use keeps working', function () {
        ($this->wildcard)();
        Certificate::query()->create([
            'app_id' => $this->shop->id, 'status' => CertificateStatus::Active, 'domains' => ['*.example.com'],
            'issued_at' => now()->subDays(75), 'expires_at' => now()->addDays(15), 'last_attempt_at' => now()->subDays(75),
        ]);

        expect(app(TlsPlanner::class)->needsIssue($this->shop))->toBeTrue();

        ($this->issue)();

        $cert = Certificate::query()->firstOrFail();

        expect($cert->status)->toBe(CertificateStatus::Active)
            ->and($cert->isUsable())->toBeTrue()
            ->and($cert->dns_pending['records'])->toHaveCount(1);
    });

    it('tells the person with an alert, once in a while', function () {
        Queue::fake();
        AlertTarget::query()->create(['name' => 'Chat', 'kind' => AlertKind::Slack, 'address' => 'https://hooks.example.com/x', 'events' => AlertEvent::keys()]);
        ($this->wildcard)();

        ($this->issue)();
        Certificate::query()->update(['dns_pending' => null, 'status' => CertificateStatus::Failed]);
        ($this->issue)();

        $messages = Queue::pushed(SendAlertJob::class);

        expect($messages)->toHaveCount(1)
            ->and($messages->first()->message->event)->toBe(AlertEvent::CertificateExpiring)
            ->and($messages->first()->message->lines[0])->toContain('_acme-challenge.example.com TXT');
    });

    it('hides records that ran out, so the page never shows dead ones', function () {
        ($this->wildcard)();
        ($this->issue)();
        $cert = Certificate::query()->firstOrFail();
        $cert->forceFill(['dns_pending' => [...$cert->dns_pending, 'expires_at' => now()->subHour()->toIso8601String()]])->save();

        expect(app(TlsPlanner::class)->pending($cert->fresh()))->toBeNull()
            ->and(app(TlsPlanner::class)->needsIssue($this->shop))->toBeTrue();
    });

    it('is finished by the five-minute domain check as soon as the record can be seen', function () {
        Queue::fake();
        app(ServerAddress::class)->save('203.0.113.7', null);
        $this->dns->records['commypanel-check.example.com'] = ['a' => ['203.0.113.7']];
        ($this->wildcard)();
        ($this->issue)();
        $record = Certificate::query()->firstOrFail()->dns_pending['records'][0];

        $this->artisan('commypanel:check-domains')->assertSuccessful();
        Queue::assertNothingPushed();

        $this->dns->records[$record['name']] = ['txt' => [$record['value']]];
        $this->artisan('commypanel:check-domains')->assertSuccessful();

        Queue::assertPushed(IssueAppCertificate::class);
    });
});

describe('the buttons on the app page', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        ($this->wildcard)();
        ($this->issue)();
        $this->record = Certificate::query()->firstOrFail()->dns_pending['records'][0];
    });

    it('shows the records to create, and whether Cloudflare is connected', function () {
        $this->get('/apps/shop?tab=domains')->assertInertia(fn (Assert $page) => $page
            ->where('app.certificate.status', 'awaiting_dns')
            ->where('app.certificate.dns_pending.records.0.name', '_acme-challenge.example.com')
            ->where('app.certificate.dns_pending.renewal', false)
            ->where('cloudflare', false));
    });

    it('says the record cannot be seen yet instead of queueing another attempt', function () {
        Queue::fake();

        $this->post('/apps/shop/certificate')->assertSessionHasErrors('certificate');
        Queue::assertNothingPushed();

        $this->dns->records[$this->record['name']] = ['txt' => [$this->record['value']]];
        $this->post('/apps/shop/certificate')->assertSessionHasNoErrors();
        Queue::assertPushed(IssueAppCertificate::class);
    });

    it('throws the waiting order away and asks for new records', function () {
        Queue::fake();

        $this->post('/apps/shop/certificate/dns')->assertSessionHasNoErrors();

        expect(Certificate::query()->firstOrFail()->dns_pending)->toBeNull()
            ->and(collect($this->host->actions())->where('action', 'cancelDnsCertificate'))->toHaveCount(1);
        Queue::assertPushed(IssueAppCertificate::class);

        $this->post('/apps/shop/certificate/dns')->assertSessionHasErrors('certificate');
    });

    it('needs the permission to manage domains', function () {
        $user = User::factory()->regular()->create();
        $this->actingAs($user);

        $this->post('/apps/shop/certificate/dns')->assertForbidden();
    });
});

describe('connecting Cloudflare', function () {
    beforeEach(fn () => $this->actingAs(User::factory()->create()));

    $good = 'cf_token_0123456789abcdefghij';

    it('tests the token against a domain, stores it encrypted and shows it nowhere', function () use ($good) {
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => $good, 'domain' => 'example.com'])
            ->assertSessionHasNoErrors();

        $settings = app(CloudflareSettings::class);

        expect($settings->configured())->toBeTrue()
            ->and($settings->token())->toBe($good)
            ->and($settings->zone())->toBe('example.com')
            ->and(Setting::read('dns.cloudflare_token'))->not->toContain($good)
            ->and(collect($this->host->actions())->where('action', 'checkCloudflare'))->toHaveCount(1)
            ->and(Severity::of('dns.cloudflare_saved'))->toBe(Severity::DANGER)
            ->and(AuditLog::query()->where('action', 'dns.cloudflare_saved')->exists())->toBeTrue()
            ->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain($good);

        $this->get('/domains')->assertInertia(fn (Assert $page) => $page
            ->where('cloudflare.connected', true)
            ->where('cloudflare.zone', 'example.com'));
        expect($this->get('/domains')->getContent())->not->toContain($good);
    });

    it('does not store a token the check refuses, or one that does not look like a token', function () use ($good) {
        $this->host->failCloudflareWith('Agent error: the token cannot edit DNS for example.com');

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => $good, 'domain' => 'example.com'])
            ->assertSessionHasErrors('token');
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => 'short', 'domain' => 'example.com'])
            ->assertSessionHasErrors('token');
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => $good, 'domain' => 'not a domain'])
            ->assertSessionHasErrors('domain');

        expect(app(CloudflareSettings::class)->configured())->toBeFalse();
    });

    it('asks for the password, and for the permission to change domain settings', function () use ($good) {
        $this->put('/settings/cloudflare', ['token' => $good, 'domain' => 'example.com'])->assertRedirect();
        expect(app(CloudflareSettings::class)->configured())->toBeFalse();

        $this->actingAs(User::factory()->regular()->create());
        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => $good, 'domain' => 'example.com'])->assertForbidden();
    });

    it('queues waiting certificates when the token arrives, and disconnects again', function () use ($good) {
        Queue::fake();
        ($this->wildcard)();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->put('/settings/cloudflare', ['token' => $good, 'domain' => 'example.com']);
        Queue::assertPushed(IssueAppCertificate::class);

        $this->withSession(['auth.password_confirmed_at' => time()])->delete('/settings/cloudflare')->assertSessionHasNoErrors();

        expect(app(CloudflareSettings::class)->configured())->toBeFalse()
            ->and(Severity::of('dns.cloudflare_removed'))->toBe(Severity::WARNING)
            ->and(AuditLog::query()->where('action', 'dns.cloudflare_removed')->exists())->toBeTrue();
    });

    it('reads a token it cannot decrypt as no token', function () {
        Setting::write('dns.cloudflare_token', 'not-encrypted');

        expect(app(CloudflareSettings::class)->configured())->toBeFalse();
    });
});

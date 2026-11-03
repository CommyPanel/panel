<?php

use App\Alerts\AlertFailed;
use App\Alerts\AlertSettings;
use App\Alerts\DeployAlerts;
use App\Auth\SyncRolesAndPermissions;
use App\Dns\DnsResolver;
use App\Enums\DeploymentStatus;
use App\Jobs\SendDeployAlert;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeDnsResolver;

const ALERT_HOOK = 'https://hooks.example.com/services/T000/B000/secret-token';

beforeEach(function () {
    app(SyncRolesAndPermissions::class)();
    app()->instance(DnsResolver::class, new FakeDnsResolver([
        'hooks.example.com' => ['a' => ['203.0.113.10']],
        'sneaky.example.com' => ['a' => ['127.0.0.1']],
        'meta.example.com' => ['a' => ['203.0.113.10', '169.254.169.254']],
        'chat.lan.example.com' => ['a' => ['10.0.0.5']],
    ]));
    $this->user = User::factory()->create();
    $this->confirmed = fn () => $this->actingAs($this->user)->withSession(['auth.password_confirmed_at' => time()]);
    $this->form = ['enabled' => true, 'format' => 'slack', 'events' => 'failures', 'url' => ALERT_HOOK];
    $this->deployment = function (DeploymentStatus $status, array $extra = []) {
        return Deployment::factory()->create([
            'app_id' => App::factory()->create(['slug' => 'shop', 'name' => 'Shop'])->id,
            'number' => 7,
            'status' => $status,
            'commit_sha' => 'abcdef1234567890',
            'commit_message' => "Fix <!channel> @everyone [x](http://evil)\nsecond line",
            ...$extra,
        ]);
    };
});

describe('the settings page', function () {
    it('keeps the address encrypted and never hands it to a page', function () {
        ($this->confirmed)()->put('/settings/notifications', $this->form)->assertSessionHasNoErrors();

        expect(Setting::read('alerts.webhook'))->not->toContain('secret-token')
            ->and(app(AlertSettings::class)->toArray())->toMatchArray(['enabled' => true, 'host' => 'hooks.example.com', 'has_url' => true])
            ->and(json_encode(app(AlertSettings::class)->toArray()))->not->toContain('secret-token');

        ($this->confirmed)()->get('/settings/notifications')->assertInertia(fn (Assert $page) => $page->component('settings/notifications')->where('alerts.host', 'hooks.example.com'));
        expect(AuditLog::query()->where('action', 'notifications.changed')->value('metadata'))->not->toContain('secret-token');
    });

    it('asks for the password to save or remove', function () {
        $this->actingAs($this->user)->put('/settings/notifications', $this->form)->assertRedirect();
        expect(Setting::read('alerts.webhook'))->toBeNull();
        $this->flushSession();

        ($this->confirmed)()->put('/settings/notifications', $this->form);
        $this->flushSession();
        $this->actingAs($this->user)->delete('/settings/notifications')->assertRedirect();
        expect(Setting::read('alerts.webhook'))->not->toBeNull();

        ($this->confirmed)()->delete('/settings/notifications');
        expect(Setting::read('alerts.webhook'))->toBeNull();
    });

    it('keeps the saved address when the field is left empty', function () {
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'url' => '', 'events' => 'all'])->assertSessionHasNoErrors();

        expect(app(AlertSettings::class)->url())->toBe(ALERT_HOOK)->and(app(AlertSettings::class)->toArray()['events'])->toBe('all');
    });

    it('needs the permission', function () {
        $nobody = User::factory()->regular()->create();
        $this->actingAs($nobody)->get('/settings/notifications')->assertForbidden();
        $this->actingAs($nobody)->put('/settings/notifications', $this->form)->assertForbidden();
        $this->actingAs($nobody)->post('/settings/notifications/test', $this->form)->assertForbidden();
    });

    it('refuses addresses that point at this server or are not https', function (string $url) {
        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'url' => $url])->assertSessionHasErrors('url');
        expect(Setting::read('alerts.webhook'))->toBeNull();
    })->with([
        'http' => 'http://hooks.example.com/x',
        'loopback' => 'https://127.0.0.1/x',
        'localhost' => 'https://localhost/x',
        'metadata' => 'https://169.254.169.254/latest',
        'mapped loopback' => 'https://[::ffff:127.0.0.1]/x',
        'ipv6 loopback' => 'https://[::1]/x',
        'credentials' => 'https://user:pass@hooks.example.com/x',
        'no host' => 'https:///x',
        'spaces' => 'https://hooks.example.com/a b',
    ]);
});

describe('sending', function () {
    it('posts a Slack message with the app, commit and error, made harmless', function () {
        Http::fake(['*' => Http::response('ok')]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        $deployment = ($this->deployment)(DeploymentStatus::Failed, ['error' => 'The app did not answer on port 8080.']);

        app(DeployAlerts::class)->deployment($deployment);

        Http::assertSent(function (HttpRequest $r) {
            $text = $r->data()['text'];

            return $r->url() === ALERT_HOOK
                && str_contains($text, 'Deployment of Shop (#7) failed')
                && str_contains($text, 'abcdef123456')
                && str_contains($text, 'did not answer on port 8080')
                && str_contains($text, 'Fix &lt;!channel&gt;')
                && ! str_contains($text, '<!channel>')
                && ! str_contains($text, '@everyone')
                && ! str_contains($text, 'second line');
        });
    });

    it('sends Discord with no mentions allowed and generic JSON with fields', function () {
        Http::fake(['*' => Http::response('', 204)]);
        $deployment = ($this->deployment)(DeploymentStatus::Active);

        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'format' => 'discord']);
        app(DeployAlerts::class)->deployment($deployment);
        Http::assertSent(fn (HttpRequest $r) => isset($r->data()['content']) && $r->data()['allowed_mentions'] === ['parse' => []]);

        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'format' => 'json', 'url' => '']);
        app(DeployAlerts::class)->deployment($deployment);
        Http::assertSent(fn (HttpRequest $r) => ($r->data()['event'] ?? null) === 'deployment.succeeded' && $r->data()['app'] === 'shop' && $r->data()['deployment'] === 7 && $r->data()['succeeded'] === true);
    });

    it('never sends the log', function () {
        Http::fake(['*' => Http::response('ok')]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        app(DeployAlerts::class)->deployment(($this->deployment)(DeploymentStatus::Failed, ['log' => 'SECRET_ENV=hunter2']));

        Http::assertSent(fn (HttpRequest $r) => ! str_contains(json_encode($r->data()), 'hunter2'));
    });

    it('does not follow redirects and refuses a name that resolves to this server', function () {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://127.0.0.1/'])]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        $deployment = ($this->deployment)(DeploymentStatus::Failed);

        expect(fn () => app(DeployAlerts::class)->deployment($deployment))->toThrow(AlertFailed::class, 'answered 302');
        Http::assertSentCount(1);

        foreach (['sneaky.example.com', 'meta.example.com', 'unknown.example.com'] as $host) {
            ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'url' => "https://{$host}/x"]);
            expect(fn () => app(DeployAlerts::class)->deployment($deployment))->toThrow(AlertFailed::class);
        }
        Http::assertSentCount(1);
    });

    it('allows a chat server in the private network', function () {
        Http::fake(['*' => Http::response('ok')]);
        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'url' => 'https://chat.lan.example.com/hook'])->assertSessionHasNoErrors();
        app(DeployAlerts::class)->deployment(($this->deployment)(DeploymentStatus::Failed));

        Http::assertSentCount(1);
    });

    it('never puts the address in an error', function () {
        Http::fake(['*' => Http::response('no', 500)]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);

        try {
            app(DeployAlerts::class)->deployment(($this->deployment)(DeploymentStatus::Failed));
        } catch (AlertFailed $e) {
            expect($e->getMessage())->not->toContain('secret-token')->toContain('500');
        }
    });
});

describe('the job', function () {
    it('is queued only when switched on, and respects the choice of events', function () {
        Queue::fake();
        $failed = ($this->deployment)(DeploymentStatus::Failed);

        SendDeployAlert::dispatchFor($failed);
        Queue::assertNothingPushed();

        ($this->confirmed)()->put('/settings/notifications', $this->form);
        SendDeployAlert::dispatchFor($failed);
        Queue::assertPushed(SendDeployAlert::class, 1);
    });

    it('stays quiet about successes unless asked, and notes a delivery problem', function () {
        Http::fake(['*' => Http::response('ok')]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        $ok = ($this->deployment)(DeploymentStatus::Active);

        (new SendDeployAlert($ok->id))->handle(app(AlertSettings::class), app(DeployAlerts::class));
        Http::assertNothingSent();

        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'events' => 'all', 'url' => '']);
        (new SendDeployAlert($ok->id))->handle(app(AlertSettings::class), app(DeployAlerts::class));
        Http::assertSentCount(1);
        expect(app(AlertSettings::class)->toArray()['last_ok_at'])->not->toBeNull();
    });

    it('notes a delivery problem on the settings and keeps the deployment as it was', function () {
        Http::fake(['*' => Http::response('no', 500)]);
        ($this->confirmed)()->put('/settings/notifications', $this->form);
        $failed = ($this->deployment)(DeploymentStatus::Failed);

        $job = new SendDeployAlert($failed->id);
        $job->withFakeQueueInteractions();
        $job->handle(app(AlertSettings::class), app(DeployAlerts::class));

        expect(app(AlertSettings::class)->toArray()['last_error'])->toContain('500')
            ->and($failed->fresh()->status)->toBe(DeploymentStatus::Failed);
    });

    it('ignores a deployment that is still running', function () {
        Http::fake();
        ($this->confirmed)()->put('/settings/notifications', [...$this->form, 'events' => 'all']);
        $running = ($this->deployment)(DeploymentStatus::Building);

        (new SendDeployAlert($running->id))->handle(app(AlertSettings::class), app(DeployAlerts::class));
        Http::assertNothingSent();
    });
});

describe('the test message', function () {
    it('uses the form without saving anything', function () {
        Http::fake(['*' => Http::response('ok')]);

        $this->actingAs($this->user)->post('/settings/notifications/test', $this->form)->assertSessionHasNoErrors();

        Http::assertSent(fn (HttpRequest $r) => $r->url() === ALERT_HOOK && str_contains($r->data()['text'], 'test message'));
        expect(Setting::read('alerts.webhook'))->toBeNull();
    });

    it('shows a failure to the person', function () {
        Http::fake(['*' => Http::response('no', 404)]);

        $this->actingAs($this->user)->post('/settings/notifications/test', $this->form)->assertSessionHasErrors('alerts');
    });
});

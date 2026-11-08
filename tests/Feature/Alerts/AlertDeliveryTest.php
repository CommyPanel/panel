<?php

use App\Alerts\AlertDelivery;
use App\Alerts\Alerter;
use App\Alerts\AlertEvent;
use App\Alerts\AlertFailed;
use App\Alerts\AlertKind;
use App\Alerts\AlertMessage;
use App\Alerts\DeployAlerts;
use App\Dns\DnsResolver;
use App\Enums\DeploymentStatus;
use App\Jobs\SendAlertJob;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\Deployment;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\FakeDnsResolver;

const ALERT_TG_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw';

beforeEach(function () {
    app()->instance(DnsResolver::class, new FakeDnsResolver([
        'hooks.example.com' => ['a' => ['203.0.113.10']],
        'api.telegram.org' => ['a' => ['149.154.167.220']],
        'sneaky.example.com' => ['a' => ['127.0.0.1']],
        'meta.example.com' => ['a' => ['203.0.113.10', '169.254.169.254']],
        'private.example.com' => ['a' => ['10.0.0.5']],
    ]));
    $this->target = function (AlertKind $kind, array $extra = []): AlertTarget {
        return AlertTarget::query()->create([
            'name' => 'Chat',
            'kind' => $kind,
            'address' => $kind === AlertKind::Telegram ? ALERT_TG_TOKEN : 'https://hooks.example.com/services/T000/secret-token',
            'chat_id' => $kind === AlertKind::Telegram ? '-1001234567890' : null,
            'events' => AlertEvent::defaults(),
            ...$extra,
        ]);
    };
    $this->message = fn (?AlertEvent $event = AlertEvent::DeploymentFailed): AlertMessage => new AlertMessage(
        $event,
        'Deployment of Shop (#7) failed',
        ['Commit abcdef123456: Fix <!channel> @everyone [x](http://evil.example.com/x)', 'The app did not answer.'],
        'https://panel.example.com/apps/shop',
        'shop',
        ['deployment' => 7, 'succeeded' => false],
    );
});

function bodyHasNoLinkOrMention(string $text): bool
{
    return ! str_contains($text, '<!channel>') && ! str_contains($text, '@everyone') && ! str_contains($text, 'http://evil');
}

describe('the formats', function () {
    it('posts Slack text with markup escaped and nothing clickable from the repo', function () {
        Http::fake(['*' => Http::response('ok')]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Slack), ($this->message)());

        Http::assertSent(function (HttpRequest $r) {
            $text = $r->data()['text'];

            return $r->url() === 'https://hooks.example.com/services/T000/secret-token'
                && str_contains($text, 'Deployment of Shop (#7) failed')
                && str_contains($text, 'Fix &lt;!channel&gt;')
                && str_contains($text, 'https://panel.example.com/apps/shop')
                && bodyHasNoLinkOrMention($text);
        });
    });

    it('posts Discord with no mentions allowed', function () {
        Http::fake(['*' => Http::response('', 204)]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Discord), ($this->message)());

        Http::assertSent(fn (HttpRequest $r) => $r->data()['allowed_mentions'] === ['parse' => []] && bodyHasNoLinkOrMention($r->data()['content']));
    });

    it('posts an Adaptive Card for Teams', function () {
        Http::fake(['*' => Http::response('', 202)]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Teams), ($this->message)());

        Http::assertSent(function (HttpRequest $r) {
            $data = $r->data();
            $card = $data['attachments'][0]['content'];

            return $data['type'] === 'message'
                && $data['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && $card['type'] === 'AdaptiveCard'
                && $card['body'][0]['text'] === 'Deployment of Shop (#7) failed'
                && $card['actions'][0]['url'] === 'https://panel.example.com/apps/shop'
                && bodyHasNoLinkOrMention(json_encode($card['body']));
        });
    });

    it('posts plain text with headers to ntfy', function () {
        Http::fake(['*' => Http::response('{}')]);
        $ntfy = ($this->target)(AlertKind::Ntfy);
        app(AlertDelivery::class)->deliver($ntfy, ($this->message)());
        app(AlertDelivery::class)->deliver($ntfy, ($this->message)(AlertEvent::DeploymentSucceeded));

        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Title', 'Deployment of Shop (#7) failed')
            && $r->hasHeader('Priority', 'high') && $r->hasHeader('Tags', 'warning')
            && $r->hasHeader('Click', 'https://panel.example.com/apps/shop')
            && str_contains($r->body(), 'The app did not answer.') && bodyHasNoLinkOrMention($r->body()));
        Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('Priority', 'default') && $r->hasHeader('Tags', 'white_check_mark'));
    });

    it('builds the Telegram address itself and sends JSON to the chat', function () {
        Http::fake(['*' => Http::response('{"ok":true}')]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Telegram), ($this->message)());

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.telegram.org/bot'.ALERT_TG_TOKEN.'/sendMessage'
            && $r->data()['chat_id'] === '-1001234567890'
            && $r->data()['disable_web_page_preview'] === true
            && bodyHasNoLinkOrMention($r->data()['text']));
    });

    it('sends generic JSON with the event and its fields', function () {
        Http::fake(['*' => Http::response('ok')]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Json), ($this->message)());

        Http::assertSent(fn (HttpRequest $r) => $r->data()['event'] === 'deployment.failed' && $r->data()['app'] === 'shop'
            && $r->data()['deployment'] === 7 && $r->data()['succeeded'] === false
            && $r->data()['url'] === 'https://panel.example.com/apps/shop');
    });

    it('never puts a log or a secret in a deployment message', function () {
        Http::fake(['*' => Http::response('ok')]);
        $deployment = Deployment::factory()->create([
            'app_id' => App::factory()->create(['slug' => 'shop', 'name' => 'Shop'])->id,
            'number' => 7, 'status' => DeploymentStatus::Failed, 'error' => 'The app did not answer on port 8080.',
            'commit_sha' => 'abcdef1234567890', 'commit_message' => "Fix @everyone\nsecond line", 'log' => 'SECRET_ENV=hunter2',
        ]);
        ($this->target)(AlertKind::Json);

        app(DeployAlerts::class)->deployment($deployment);

        Http::assertSent(fn (HttpRequest $r) => $r->data()['event'] === 'deployment.failed'
            && str_contains($r->data()['text'], 'did not answer on port 8080')
            && $r->data()['commit'] === 'abcdef123456'
            && ! str_contains(json_encode($r->data()), 'hunter2') && ! str_contains($r->data()['text'], 'second line'));
    });
});

describe('the guard', function () {
    it('refuses private and loopback addresses for every kind', function (AlertKind $kind, string $host) {
        Http::fake();
        $target = ($this->target)($kind, $kind === AlertKind::Telegram ? [] : ['address' => "https://{$host}/x"]);

        if ($kind === AlertKind::Telegram) {
            // the host of Telegram is fixed; if DNS pointed it at this server it is refused too
            app()->instance(DnsResolver::class, new FakeDnsResolver(['api.telegram.org' => ['a' => [$host === 'meta.example.com' ? '169.254.169.254' : '127.0.0.1']]]));
        }

        expect(fn () => app(AlertDelivery::class)->deliver($target, ($this->message)()))->toThrow(AlertFailed::class);
        Http::assertNothingSent();
    })->with(function () {
        foreach (AlertKind::cases() as $kind) {
            foreach (['sneaky.example.com', 'meta.example.com', '127.0.0.1', 'unknown.example.com'] as $host) {
                yield "{$kind->value} {$host}" => [$kind, $host];
            }
        }
    });

    it('does not follow redirects and never puts the address in an error', function () {
        Http::fake(['*' => Http::response('', 302, ['Location' => 'https://127.0.0.1/'])]);
        $target = ($this->target)(AlertKind::Slack);

        try {
            app(AlertDelivery::class)->deliver($target, ($this->message)());
            $this->fail('A 302 is not a success.');
        } catch (AlertFailed $e) {
            expect($e->getMessage())->toContain('302')->not->toContain('secret-token');
        }
        Http::assertSentCount(1);
    });

    it('allows a chat server in a private network', function () {
        Http::fake(['*' => Http::response('ok')]);
        app(AlertDelivery::class)->deliver(($this->target)(AlertKind::Slack, ['address' => 'https://private.example.com/hook']), ($this->message)());

        Http::assertSentCount(1);
    });
});

describe('who gets what', function () {
    it('queues one job per enabled target that listens to the event', function () {
        Queue::fake();
        $a = ($this->target)(AlertKind::Slack, ['events' => ['deployment.failed']]);
        ($this->target)(AlertKind::Discord, ['events' => ['app.down']]);
        ($this->target)(AlertKind::Json, ['events' => ['deployment.failed'], 'enabled' => false]);
        $d = ($this->target)(AlertKind::Ntfy, ['events' => ['deployment.failed', 'app.down']]);

        app(Alerter::class)->send(($this->message)());

        Queue::assertPushed(SendAlertJob::class, 2);
        Queue::assertPushed(SendAlertJob::class, fn ($j) => $j->targetId === $a->id);
        Queue::assertPushed(SendAlertJob::class, fn ($j) => $j->targetId === $d->id);
    });

    it('is muted for an app whose alerts are switched off, but not for the server', function () {
        Queue::fake();
        App::factory()->create(['slug' => 'shop', 'alerts_enabled' => false]);
        ($this->target)(AlertKind::Slack, ['events' => ['deployment.failed', 'disk.warning']]);

        app(Alerter::class)->send(($this->message)());
        Queue::assertNothingPushed();

        app(Alerter::class)->send(new AlertMessage(AlertEvent::DiskWarning, 'Disk', [], 'https://panel.example.com/server'));
        Queue::assertPushed(SendAlertJob::class, 1);
    });

    it('queues nothing for a message without an event', function () {
        Queue::fake();
        ($this->target)(AlertKind::Slack);

        app(Alerter::class)->send(new AlertMessage(null, 'x', [], 'https://panel.example.com'));
        Queue::assertNothingPushed();
    });

    it('starts new targets with the events that are on by default', function () {
        expect(AlertEvent::defaults())->toContain('deployment.failed', 'app.down', 'disk.critical', 'backup.failed')
            ->not->toContain('deployment.succeeded', 'app.recovered', 'disk.recovered', 'database.recovered');
    });
});

describe('the job', function () {
    it('records a success', function () {
        Http::fake(['*' => Http::response('ok')]);
        $target = ($this->target)(AlertKind::Slack, ['last_error' => 'old']);

        $job = new SendAlertJob($target->id, ($this->message)());
        $job->withFakeQueueInteractions();
        $job->handle(app(AlertDelivery::class), app(Alerter::class));

        expect($target->fresh()->last_ok_at)->not->toBeNull()->and($target->fresh()->last_error)->toBeNull();
    });

    it('notes a failure, retries, and never throws', function () {
        Http::fake(['*' => Http::response('no', 500)]);
        $target = ($this->target)(AlertKind::Slack);

        $job = new SendAlertJob($target->id, ($this->message)());
        $job->withFakeQueueInteractions();
        $job->handle(app(AlertDelivery::class), app(Alerter::class));

        $job->assertReleased(30);
        expect($target->fresh()->last_error)->toContain('500')->not->toContain('secret-token')
            ->and($job->tries)->toBe(3)->and($job->backoff)->toBe([30, 120]);
    });

    it('does not send to a target that was switched off meanwhile', function () {
        Http::fake();
        $target = ($this->target)(AlertKind::Slack, ['enabled' => false]);

        (new SendAlertJob($target->id, ($this->message)()))->handle(app(AlertDelivery::class), app(Alerter::class));
        Http::assertNothingSent();
    });

    it('does not let a delivery problem touch what raised the alert', function () {
        Http::fake(['*' => Http::response('no', 500)]);
        ($this->target)(AlertKind::Slack);
        $deployment = Deployment::factory()->create([
            'app_id' => App::factory()->create(['slug' => 'shop'])->id, 'number' => 7, 'status' => DeploymentStatus::Failed,
        ]);

        DeployAlerts::dispatchFor($deployment);

        expect($deployment->fresh()->status)->toBe(DeploymentStatus::Failed);
    });
});

describe('the test message', function () {
    it('goes out at once and returns the error or null', function () {
        Http::fake(['*' => Http::sequence()->push('ok')->push('no', 404)]);
        $target = ($this->target)(AlertKind::Slack);

        expect(app(Alerter::class)->test($target))->toBeNull()->and($target->fresh()->last_ok_at)->not->toBeNull();
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->data()['text'], 'test message'));

        expect(app(Alerter::class)->test($target))->toContain('404');
    });
});

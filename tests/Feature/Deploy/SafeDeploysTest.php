<?php

use App\Alerts\AlertEvent;
use App\Alerts\AlertKind;
use App\Auth\SyncRolesAndPermissions;
use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Safe\RolloutService;
use App\Deploy\Safe\WatchService;
use App\Deploy\Source\SourceFetcher;
use App\Enums\DeploymentStatus;
use App\Host\Data\TrafficStat;
use App\Host\HostDriver;
use App\Jobs\AdvanceRolloutJob;
use App\Jobs\RunDeployment;
use App\Jobs\SendAlertJob;
use App\Jobs\WatchDeploymentJob;
use App\Models\AlertTarget;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Rollout;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    // Only the looks are held back; deployments run at once, as in the other deploy tests.
    Queue::fake([WatchDeploymentJob::class, AdvanceRolloutJob::class, SendAlertJob::class]);
    app(SyncRolesAndPermissions::class)();
    Carbon::setTestNow('2026-11-07 12:00:00');
    // Messages are on, so the ones the panel sends by itself can be seen being queued.
    AlertTarget::query()->create(['name' => 'Chat', 'kind' => AlertKind::Slack, 'address' => 'https://hooks.example.com/x', 'events' => AlertEvent::defaults()]);

    $this->health = new FakeHealthChecker;
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, $this->health);
    $this->host = app(HostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);

    $this->deploy = fn (): Deployment => app(Deployer::class)->deploy($this->shop->fresh())->fresh();
    $this->site = fn () => $this->host->sites()['shop'] ?? null;
    $this->running = fn (Deployment $d): bool => collect($this->host->listContainers())->contains(fn ($c) => $c->name === $d->container_name && $c->state === 'running');
    // What the requests looked like: the closure is given the ports of the two releases.
    $this->traffic = function (array $byPort) {
        $this->host->useTraffic(fn () => $byPort);
    };
    $this->first = ($this->deploy)();
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->dataPath);
});

function reqs(int $requests, int $errors = 0, float $p95 = 100.0): TrafficStat
{
    return new TrafficStat($requests, $errors, $p95 / 2, $p95, $p95 * 2);
}

describe('watching a release after it goes live', function () {
    it('keeps the release it replaced running, and says it is watching', function () {
        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Active)
            ->and($second->watch_state)->toBe('watching')
            ->and($second->watch_until->equalTo(now()->addMinutes(5)))->toBeTrue()
            ->and($second->live_at)->not->toBeNull()
            ->and($this->shop->fresh()->active_deployment_id)->toBe($second->id)
            ->and(($this->running)($this->first))->toBeTrue()
            ->and($this->first->fresh()->status)->toBe(DeploymentStatus::Superseded)
            ->and(($this->site)()->upstreamPort)->toBe($second->host_port);
        Queue::assertPushed(WatchDeploymentJob::class, fn ($job) => $job->deploymentId === $second->id);
    });

    it('does not watch when it is switched off, when it is a first release, or when it was asked not to', function () {
        expect($this->first->watch_state)->toBeNull();

        $this->shop->update(['watch_enabled' => false]);
        $second = ($this->deploy)();
        expect($second->watch_state)->toBeNull()->and(($this->running)($this->first))->toBeFalse();

        $this->shop->update(['watch_enabled' => true]);
        $third = ($this->deploy)();
        expect($third->watch_state)->toBe('watching');
        $this->host->stopContainer($third->container_name);
        $third->forceFill(['status' => DeploymentStatus::RolledBack, 'image_available' => true])->save();
        $retry = app(Deployer::class)->retry($this->shop->fresh(), $third->fresh(), $this->user, watch: false)->fresh();
        expect($retry->watch_state)->toBeNull();
    });

    it('does not watch an app with no domain, because nobody visits it', function () {
        Domain::query()->delete();

        expect(($this->deploy)()->watch_state)->toBeNull();
    });

    it('lets a release that holds up stay, and stops the old container when the watch ends', function () {
        $second = ($this->deploy)();
        ($this->traffic)([$this->first->host_port => reqs(5000, 5), $second->host_port => reqs(400, 3)]);
        Carbon::setTestNow(now()->addMinutes(6));

        app(WatchService::class)->tick($second);

        $second->refresh();
        expect($second->watch_state)->toBe('passed')
            ->and($second->status)->toBe(DeploymentStatus::Active)
            ->and(($this->running)($this->first))->toBeFalse()
            ->and($this->first->fresh()->status)->toBe(DeploymentStatus::Superseded)
            ->and(AuditLog::query()->where('action', 'deploy.watch_passed')->exists())->toBeTrue();
    });

    it('accepts a release when there was too little traffic to judge it, and says so', function () {
        $second = ($this->deploy)();
        ($this->traffic)([$second->host_port => reqs(3, 3)]);
        Carbon::setTestNow(now()->addMinutes(6));

        app(WatchService::class)->tick($second);

        expect($second->fresh()->watch_state)->toBe('ended')
            ->and($second->fresh()->watch_note)->toContain('Not enough traffic');
    });

    it('rolls back by itself when the new release is clearly worse, and puts traffic straight back', function () {
        $second = ($this->deploy)();
        ($this->traffic)([$this->first->host_port => reqs(5000, 5), $second->host_port => reqs(300, 60)]);

        app(WatchService::class)->tick($second); // the first bad look is only a strike
        expect($second->fresh()->watch_state)->toBe('watching')->and($second->fresh()->watch_strikes)->toBe(1);
        Carbon::setTestNow(now()->addSeconds(15));
        app(WatchService::class)->tick($second);

        $second->refresh();
        expect($second->status)->toBe(DeploymentStatus::RolledBack)
            ->and($second->watch_state)->toBe('rolled_back')
            ->and($second->error)->toContain('error rate')->toContain('20%')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->first->id)
            ->and($this->first->fresh()->status)->toBe(DeploymentStatus::Active)
            ->and(($this->site)()->upstreamPort)->toBe($this->first->host_port)
            ->and(($this->running)($second))->toBeFalse()
            ->and(($this->running)($this->first))->toBeTrue()
            ->and($second->log)->toContain('Rolling back automatically')
            ->and(AuditLog::query()->where('action', 'deploy.auto_rolled_back')->first()->metadata['back_to'])->toBe($this->first->number);
        Queue::assertPushed(SendAlertJob::class, fn ($job) => $job->message->event === AlertEvent::DeploymentRolledBack && $job->message->data['deployment'] === $second->number);
    });

    it('does not roll back over a single bad look that clears', function () {
        $second = ($this->deploy)();
        ($this->traffic)([$this->first->host_port => reqs(5000, 5), $second->host_port => reqs(300, 60)]);
        app(WatchService::class)->tick($second);
        ($this->traffic)([$this->first->host_port => reqs(5000, 5), $second->host_port => reqs(600, 4)]);
        Carbon::setTestNow(now()->addSeconds(15));
        app(WatchService::class)->tick($second);

        expect($second->fresh()->watch_state)->toBe('watching')->and($second->fresh()->watch_strikes)->toBe(0)
            ->and($second->fresh()->status)->toBe(DeploymentStatus::Active);
    });

    it('rolls back at once when the new container stops', function () {
        $second = ($this->deploy)();
        $this->host->stopContainer($second->container_name);

        app(WatchService::class)->tick($second);

        expect($second->fresh()->status)->toBe(DeploymentStatus::RolledBack)
            ->and($second->fresh()->error)->toContain('stopped running')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->first->id);
    });

    it('cannot roll back when the old container is gone, and says so', function () {
        $second = ($this->deploy)();
        $this->host->removeContainer($this->first->container_name);
        $this->host->stopContainer($second->container_name);

        app(WatchService::class)->tick($second);

        expect($second->fresh()->status)->toBe(DeploymentStatus::Active)
            ->and($second->fresh()->watch_state)->toBe('ended')
            ->and($second->fresh()->watch_note)->toContain('no longer running');
    });

    it('ends the watch when a newer deployment starts, so the old container is stopped first', function () {
        $second = ($this->deploy)();
        $third = ($this->deploy)();

        expect($second->fresh()->watch_state)->toBe('ended')
            ->and(($this->running)($this->first))->toBeFalse()
            ->and($third->fresh()->watch_state)->toBe('watching')
            ->and(($this->running)($second))->toBeTrue();
    });

    it('can be ended by a person, who accepts the release', function () {
        $second = ($this->deploy)();

        $this->post("/apps/shop/deployments/{$second->number}/end-watch")->assertSessionHasNoErrors();

        expect($second->fresh()->watch_state)->toBe('ended')->and(($this->running)($this->first))->toBeFalse();
        $this->post("/apps/shop/deployments/{$second->number}/end-watch")->assertNotFound();
    });

    it('is looked at again by the job until the watch is over', function () {
        $second = ($this->deploy)();
        Queue::fake([WatchDeploymentJob::class]);

        (new WatchDeploymentJob($second->id))->handle(app(WatchService::class));
        Queue::assertPushed(WatchDeploymentJob::class, 1);

        $second->forceFill(['watch_state' => 'passed'])->save();
        (new WatchDeploymentJob($second->id))->handle(app(WatchService::class));
        Queue::assertPushed(WatchDeploymentJob::class, 1);
    });
});

describe('trying a rolled back release again', function () {
    beforeEach(function () {
        $this->second = ($this->deploy)();
        ($this->traffic)([$this->first->host_port => reqs(5000, 5), $this->second->host_port => reqs(300, 60)]);
        app(WatchService::class)->tick($this->second);
        Carbon::setTestNow(now()->addSeconds(15));
        app(WatchService::class)->tick($this->second);
        ($this->traffic)([]);
        $this->second->refresh();
    });

    it('goes live again from the same image, without building, when a person asks', function () {
        expect($this->second->status)->toBe(DeploymentStatus::RolledBack);
        $builds = collect($this->host->actions())->where('action', 'buildImage')->count();

        $this->post("/apps/shop/deployments/{$this->second->number}/retry", ['watch' => true])->assertSessionHasNoErrors();

        $retry = Deployment::query()->where('retry', true)->firstOrFail();
        expect($retry->status)->toBe(DeploymentStatus::Active)
            ->and($retry->image_tag)->toBe($this->second->image_tag)
            ->and($retry->log)->toContain('Going live again')
            ->and($retry->watch_state)->toBe('watching')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($retry->id)
            ->and(collect($this->host->actions())->where('action', 'buildImage')->count())->toBe($builds)
            ->and(AuditLog::query()->where('action', 'deploy.retry_queued')->exists())->toBeTrue();
    });

    it('can go live without being watched this time', function () {
        $this->post("/apps/shop/deployments/{$this->second->number}/retry", ['watch' => false])->assertSessionHasNoErrors();

        expect(Deployment::query()->where('retry', true)->firstOrFail()->watch_state)->toBeNull();
    });

    it('is only for releases that were rolled back, and needs the deploy permission', function () {
        $this->post("/apps/shop/deployments/{$this->first->number}/retry", ['watch' => true])->assertSessionHasErrors('deploy');

        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view');
        $this->actingAs($viewer)->post("/apps/shop/deployments/{$this->second->number}/retry", ['watch' => true])->assertForbidden();

        expect(fn () => app(Deployer::class)->retry($this->shop->fresh(), $this->first->fresh()))->toThrow(DeploymentFailed::class);
    });

    it('is not shown as a rollback in what people see', function () {
        $this->post("/apps/shop/deployments/{$this->second->number}/retry", ['watch' => true]);

        $this->get('/apps/shop')->assertInertia(fn ($page) => $page
            ->where('deployments.0.is_retry', true)->where('deployments.0.is_rollback', false)
            ->where('deployments.1.can_retry', true)->where('deployments.1.status', 'rolled_back'));
    });
});

describe('a gradual rollout', function () {
    beforeEach(function () {
        $this->shop->update(['rollout_enabled' => true, 'rollout_steps' => [10, 50], 'rollout_hold_minutes' => 5, 'rollout_auto' => true]);
        $this->new = ($this->deploy)();
        $this->rollout = Rollout::query()->firstOrFail();
        $this->ports = fn () => [$this->first->host_port, $this->new->host_port];
    });

    it('starts the new release next to the live one with a share of the visitors, and does not make it live', function () {
        expect($this->new->status)->toBe(DeploymentStatus::Rollout)
            ->and($this->rollout->state)->toBe(Rollout::RUNNING)
            ->and($this->rollout->percent)->toBe(10)
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->first->id)
            ->and(($this->running)($this->first))->toBeTrue()->and(($this->running)($this->new))->toBeTrue()
            ->and(($this->site)()->upstreamPort)->toBe($this->first->host_port)
            ->and(($this->site)()->rollout->port)->toBe($this->new->host_port)
            ->and(($this->site)()->rollout->percent)->toBe(10)
            ->and($this->new->log)->toContain('10% → 50% → 100%');
        Queue::assertPushed(AdvanceRolloutJob::class);
    });

    it('is not for a first release, a rollback or an apply, which are switched at once', function () {
        $service = app(RolloutService::class);
        $app = $this->shop->fresh();

        expect($service->shouldRoll($app, new Deployment(['rolled_back_from_id' => 1]), $this->first))->toBeFalse()
            ->and($service->shouldRoll($app, (new Deployment)->forceFill(['settings_only' => true]), $this->first))->toBeFalse()
            ->and($service->shouldRoll($app, new Deployment, null))->toBeFalse();
    });

    it('blocks a new deployment and a push while it is under way', function () {
        $this->post('/apps/shop/deployments')->assertSessionHasErrors('deploy');
        expect($this->shop->fresh()->isDeploying())->toBeTrue();
    });

    it('moves to the next share when the step held up for its time, then gives everyone the release', function () {
        ($this->traffic)([$this->first->host_port => reqs(900, 9), $this->new->host_port => reqs(100, 1)]);
        $service = app(RolloutService::class);

        $service->tick($this->rollout); // not yet: the step has not run its five minutes
        expect($this->rollout->fresh()->percent)->toBe(10);

        Carbon::setTestNow(now()->addMinutes(5)->addSeconds(1));
        $service->tick($this->rollout);
        expect($this->rollout->fresh()->percent)->toBe(50)->and(($this->site)()->rollout->percent)->toBe(50);

        Carbon::setTestNow(now()->addMinutes(5)->addSeconds(1));
        $service->tick($this->rollout);

        $this->new->refresh();
        expect($this->rollout->fresh()->state)->toBe(Rollout::PROMOTED)
            ->and($this->new->status)->toBe(DeploymentStatus::Active)
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->new->id)
            ->and(($this->site)()->upstreamPort)->toBe($this->new->host_port)
            ->and(($this->site)()->rollout)->toBeNull()
            ->and(($this->running)($this->first))->toBeFalse()
            ->and($this->new->watch_state)->toBeNull() // it has just been proven; no second watch
            ->and(AuditLog::query()->where('action', 'deploy.rollout_promoted')->exists())->toBeTrue();
    });

    it('takes the share away and stops the release when it does clearly worse', function () {
        ($this->traffic)([$this->first->host_port => reqs(900, 3), $this->new->host_port => reqs(100, 30)]);
        $service = app(RolloutService::class);

        $service->tick($this->rollout);
        expect($this->rollout->fresh()->state)->toBe(Rollout::RUNNING)->and($this->rollout->fresh()->strikes)->toBe(1);
        $service->tick($this->rollout);

        $this->new->refresh();
        expect($this->rollout->fresh()->state)->toBe(Rollout::ROLLED_BACK)
            ->and($this->new->status)->toBe(DeploymentStatus::RolledBack)
            ->and($this->new->error)->toContain('error rate')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->first->id)
            ->and(($this->site)()->rollout)->toBeNull()
            ->and(($this->site)()->upstreamPort)->toBe($this->first->host_port)
            ->and(($this->running)($this->new))->toBeFalse()->and(($this->running)($this->first))->toBeTrue()
            ->and(AuditLog::query()->where('action', 'deploy.rollout_rolled_back')->exists())->toBeTrue();
        Queue::assertPushed(SendAlertJob::class, fn ($job) => $job->message->event === AlertEvent::DeploymentRolledBack);
    });

    it('waits for a person when there is too little traffic to decide, and does not guess', function () {
        ($this->traffic)([$this->first->host_port => reqs(40), $this->new->host_port => reqs(2)]);
        $service = app(RolloutService::class);

        Carbon::setTestNow(now()->addMinutes(6));
        $service->tick($this->rollout);
        expect($this->rollout->fresh()->state)->toBe(Rollout::RUNNING); // held longer, up to three times the hold

        Carbon::setTestNow(now()->addMinutes(10));
        $service->tick($this->rollout);
        expect($this->rollout->fresh()->state)->toBe(Rollout::WAITING)->and($this->rollout->fresh()->note)->toContain('not enough traffic');
    });

    it('waits for a person after each step when it is set to move on by hand', function () {
        $this->rollout->forceFill(['auto' => false])->save();
        ($this->traffic)([$this->first->host_port => reqs(900, 9), $this->new->host_port => reqs(100, 1)]);
        Carbon::setTestNow(now()->addMinutes(6));

        app(RolloutService::class)->tick($this->rollout);

        expect($this->rollout->fresh()->state)->toBe(Rollout::WAITING)->and($this->rollout->fresh()->percent)->toBe(10);
    });

    it('lets a person move on, give everyone the release, or abort', function () {
        $this->post('/apps/shop/rollout/advance')->assertSessionHasNoErrors();
        expect($this->rollout->fresh()->percent)->toBe(50);

        $this->post('/apps/shop/rollout/promote')->assertSessionHasNoErrors();
        expect($this->rollout->fresh()->state)->toBe(Rollout::PROMOTED)->and($this->shop->fresh()->active_deployment_id)->toBe($this->new->id);

        $this->post('/apps/shop/rollout/abort')->assertNotFound(); // nothing is under way any more
    });

    it('can be aborted by a person, which puts everything back on the old release', function () {
        $this->post('/apps/shop/rollout/abort')->assertSessionHasNoErrors();

        expect($this->rollout->fresh()->state)->toBe(Rollout::ABORTED)
            ->and($this->new->fresh()->status)->toBe(DeploymentStatus::RolledBack)
            ->and($this->new->fresh()->error)->toContain('aborted')
            ->and(($this->site)()->rollout)->toBeNull()
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->first->id);
        Queue::assertNotPushed(SendAlertJob::class, fn ($job) => ($job->message->data['deployment'] ?? null) === $this->new->number); // a person did it; no message
    });

    it('needs the deploy permission to be steered', function () {
        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view');

        foreach (['advance', 'promote', 'abort'] as $action) {
            $this->actingAs($viewer)->post("/apps/shop/rollout/{$action}")->assertForbidden();
        }
    });

    it('rolls the new release out to everyone when the old one stops, rather than leave its visitors with errors', function () {
        $this->host->stopContainer($this->first->container_name);

        app(RolloutService::class)->tick($this->rollout);

        expect($this->rollout->fresh()->state)->toBe(Rollout::PROMOTED)->and($this->new->fresh()->status)->toBe(DeploymentStatus::Active);
    });

    it('is rolled back when the new container stops', function () {
        $this->host->stopContainer($this->new->container_name);

        app(RolloutService::class)->tick($this->rollout);

        expect($this->rollout->fresh()->state)->toBe(Rollout::ROLLED_BACK)->and($this->new->fresh()->error)->toContain('stopped running');
    });

    it('cleans up the steps it is given', function () {
        $this->shop->update(['rollout_steps' => [50, 10, 10, 0, 150, 'x', 25, 60, 70]]);

        expect(RolloutService::stepsFor($this->shop->fresh()))->toBe([10, 25, 50, 60])
            ->and(RolloutService::stepsFor(new App(['rollout_steps' => null])))->toBe([10, 50]);
    });
});

it('queues the next look from the scheduler for anything still open', function () {
    $this->shop->update(['rollout_enabled' => true]);
    ($this->deploy)();
    Queue::fake([WatchDeploymentJob::class, AdvanceRolloutJob::class]);

    $this->artisan('commypanel:tick-safe-deployments')->assertSuccessful();

    Queue::assertPushed(AdvanceRolloutJob::class, 1);
});

it('does not queue a job for a deployment that never ran', function () {
    Queue::assertNotPushed(RunDeployment::class);
});

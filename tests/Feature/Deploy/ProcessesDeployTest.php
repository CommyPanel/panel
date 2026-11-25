<?php

use App\Deploy\AppProcesses;
use App\Deploy\ControlApp;
use App\Deploy\DeactivateApp;
use App\Deploy\Deployer;
use App\Deploy\DestroyApp;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Safe\RolloutService;
use App\Deploy\Safe\WatchService;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStatus;
use App\Enums\DeployStrategy;
use App\Enums\ProcessKind;
use App\Enums\Stack;
use App\Host\Data\ContainerSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Jobs\AdvanceRolloutJob;
use App\Jobs\WatchDeploymentJob;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AppProcess;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Rollout;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

/**
 * Extra processes (workers, scheduler) next to the web container: which container starts when, and what
 * happens to them when the release is replaced, rolled back, stopped, deactivated or deleted.
 */
beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0, 'commypanel.deploy.keep_images' => 5]);
    Sleep::fake();

    $this->health = new FakeHealthChecker;
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, $this->health);
    app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health', 'memory_limit_mb' => 256]);

    $this->process = fn (string $name, ProcessKind $kind, array $attributes = []) => AppProcess::query()->create([
        'app_id' => $this->shop->id, 'name' => $name, 'kind' => $kind, 'enabled' => true,
        'overlap' => $kind->defaultOverlap(), 'stop_grace_seconds' => $kind->defaultGraceSeconds(), ...$attributes,
    ]);
    $this->deploy = fn (): Deployment => app(Deployer::class)->deploy($this->shop->fresh())->fresh();
    $this->running = fn (string $name): bool => collect($this->host->listContainers())->contains(fn ($c) => $c->name === $name && $c->state === 'running');
    $this->exists = fn (string $name): bool => collect($this->host->listContainers())->contains(fn ($c) => $c->name === $name);
    /** The host actions as "action name", in order. */
    $this->actions = fn () => collect($this->host->actions())->map(fn ($a) => $a['action'].' '.($a['args']['name'] ?? ($a['args']['spec']->name ?? '')))->values()->all();
    $this->spec = fn (string $name): ContainerSpec => collect($this->host->actions())->where('action', 'runContainer')->map(fn ($a) => $a['args']['spec'])->first(fn ($s) => $s->name === $name);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

describe('starting a release', function () {
    it('starts the web container and the queue worker, then the scheduler only after traffic has moved', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'APP_KEY', 'value' => 'base64:abc']);

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Active)
            ->and(($this->actions)())->toBe(['buildImage ', 'runContainer shop-1', 'runContainer shop-1-queue', 'runContainer shop-1-scheduler'])
            ->and($deployment->processes)->toHaveCount(2)
            ->and($deployment->processes[1])->toMatchArray(['name' => 'queue', 'kind' => 'queue', 'overlap' => 'overlap', 'replicas' => 1, 'container' => 'shop-1-queue', 'started' => true, 'command' => 'php artisan queue:work --tries=3 --timeout=90'])
            ->and($deployment->processes[0])->toMatchArray(['name' => 'scheduler', 'overlap' => 'stop_first', 'container' => 'shop-1-scheduler', 'started' => true, 'grace' => 30])
            ->and($deployment->log)->toContain('Starting queue (shop-1-queue).')->toContain('Starting scheduler (shop-1-scheduler).');
    });

    it('gives a process the same environment, volumes and network as the web container, an argument list as command, and no port', function () {
        ($this->process)('queue', ProcessKind::Queue, ['command' => "php artisan queue:work --queue='high,default'", 'stop_grace_seconds' => 90, 'memory_limit_mb' => 128]);
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'APP_KEY', 'value' => 'base64:abc']);

        ($this->deploy)();

        $web = ($this->spec)('shop-1');
        $queue = ($this->spec)('shop-1-queue');

        expect($queue->command)->toBe(['php', 'artisan', 'queue:work', '--queue=high,default'])
            ->and($queue->image)->toBe('commypanel/shop:1')
            ->and($queue->hostPort)->toBe(0)
            ->and($queue->containerPort)->toBe(0)
            ->and($queue->env)->toBe($web->env)
            ->and($queue->env['APP_KEY'])->toBe('base64:abc')
            ->and($queue->network)->toBe($web->network)
            ->and($queue->volumes)->toBe($web->volumes)
            ->and($queue->restart)->toBe('unless-stopped')
            ->and($queue->process)->toBe('queue')
            ->and($queue->stopTimeout)->toBe(90)
            ->and($queue->memoryLimitMb)->toBe(128)
            ->and($web->command)->toBeNull()
            ->and($web->restart)->toBeNull();
    });

    it('uses the app\'s memory limit when the process has none, and starts a container per replica', function () {
        ($this->process)('queue', ProcessKind::Queue, ['replicas' => 3]);

        $deployment = ($this->deploy)();

        expect(($this->spec)('shop-1-queue-1')->memoryLimitMb)->toBe(256)
            ->and(($this->running)('shop-1-queue-1'))->toBeTrue()
            ->and(($this->running)('shop-1-queue-2'))->toBeTrue()
            ->and(($this->running)('shop-1-queue-3'))->toBeTrue()
            ->and($deployment->processes[0]['containers'])->toBe(['shop-1-queue-1', 'shop-1-queue-2', 'shop-1-queue-3']);
    });

    it('starts nothing extra for an app without processes, or with them switched off', function () {
        ($this->process)('queue', ProcessKind::Queue, ['enabled' => false]);

        $deployment = ($this->deploy)();

        expect(($this->actions)())->toBe(['buildImage ', 'runContainer shop-1'])->and($deployment->processes)->toBeNull();
    });

    it('never replicates a singleton', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler, ['replicas' => 4]);

        $deployment = ($this->deploy)();

        expect($deployment->processes[0]['replicas'])->toBe(1)->and(($this->exists)('shop-1-scheduler-2'))->toBeFalse();
    });

    it('fails before anything starts when a command cannot be run, and keeps the old version serving', function () {
        $first = ($this->deploy)();
        ($this->process)('queue', ProcessKind::Queue, ['command' => "php 'unclosed"]);

        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Failed)
            ->and($second->error)->toContain('queue process is not valid')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
            ->and(($this->exists)('shop-2'))->toBeFalse();
    });

    it('fails the deployment when a worker stops right after it started, with its output, and cleans up', function () {
        $first = ($this->deploy)();
        ($this->process)('queue', ProcessKind::Queue);
        ($this->process)('scheduler', ProcessKind::Scheduler);
        $this->host->exitContainersEndingWith('-queue');

        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Failed)
            ->and($second->error)->toContain('shop-2-queue stopped right after it started')
            ->and($second->log)->toContain('output of shop-2-queue')->toContain('[fake] logs for shop-2-queue')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
            ->and(($this->exists)('shop-2'))->toBeFalse()
            ->and(($this->exists)('shop-2-queue'))->toBeFalse()
            ->and(($this->exists)('shop-2-scheduler'))->toBeFalse()
            ->and(($this->running)('shop-1'))->toBeTrue();
    });

    it('does not fail a release because a singleton could not start after traffic moved', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        $this->host->failOn('runContainer'); // the web container: nothing started, so failure is expected
        $failed = ($this->deploy)();
        expect($failed->status)->toBe(DeploymentStatus::Failed);

        // The next time the web container starts and only the scheduler is refused.
        $host = new class extends FakeHostDriver
        {
            public function runContainer(ContainerSpec $spec): void
            {
                if ($spec->process === 'scheduler') {
                    throw new HostOperationFailed('Agent error: no room');
                }

                parent::runContainer($spec);
            }
        };
        app()->instance(HostDriver::class, $host);

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Active)
            ->and($deployment->log)->toContain('Warning: could not start scheduler: Agent error: no room')
            ->and($deployment->processes[0]['started'])->toBeFalse();
    });
});

describe('replacing a release', function () {
    it('stops the old scheduler, starts the new one, and lets the old worker run on beside the new one', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
        ($this->deploy)();
        $before = count($this->host->actions());

        $second = ($this->deploy)();
        $actions = array_slice(($this->actions)(), $before);

        // The new web and worker start first, the old scheduler stops (grace period), and only then the new one starts.
        expect($actions)->toContain('runContainer shop-2', 'runContainer shop-2-queue', 'stopContainer shop-1-scheduler', 'runContainer shop-2-scheduler')
            ->and(array_search('runContainer shop-2-queue', $actions, true))->toBeLessThan(array_search('stopContainer shop-1-scheduler', $actions, true))
            ->and(array_search('stopContainer shop-1-scheduler', $actions, true))->toBeLessThan(array_search('runContainer shop-2-scheduler', $actions, true))
            ->and($second->watch_state)->toBe('watching')
            // The watch keeps the old web container and worker for a quick way back; the old scheduler is stopped, not removed.
            ->and(($this->running)('shop-1'))->toBeTrue()
            ->and(($this->running)('shop-1-queue'))->toBeTrue()
            ->and(($this->exists)('shop-1-scheduler'))->toBeTrue()
            ->and(($this->running)('shop-1-scheduler'))->toBeFalse()
            ->and(($this->running)('shop-2-scheduler'))->toBeTrue();
    });

    it('removes every container of the old release when the watch ends', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
        ($this->deploy)();
        ($this->deploy)();

        app(WatchService::class)->finish($this->shop->fresh(), 'test');

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler'] as $name) {
            expect(($this->exists)($name))->toBeFalse($name);
        }

        expect(($this->running)('shop-2'))->toBeTrue()->and(($this->running)('shop-2-queue'))->toBeTrue()->and(($this->running)('shop-2-scheduler'))->toBeTrue();
    });

    it('removes the old release at once when nothing is watched', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        ($this->deploy)();

        ($this->deploy)();

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler'] as $name) {
            expect(($this->exists)($name))->toBeFalse($name);
        }
    });

    it('puts the processes back where they were when an automatic rollback goes back to the old release', function () {
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
        $first = ($this->deploy)();
        $second = ($this->deploy)();

        app(WatchService::class)->rollBack($second->fresh(), $first->fresh(), 'more errors than before');

        expect(($this->running)('shop-1'))->toBeTrue()
            ->and(($this->running)('shop-1-queue'))->toBeTrue()
            ->and(($this->running)('shop-1-scheduler'))->toBeTrue()
            ->and(($this->exists)('shop-2'))->toBeFalse()
            ->and(($this->exists)('shop-2-queue'))->toBeFalse()
            ->and(($this->exists)('shop-2-scheduler'))->toBeFalse()
            ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id);
    });

    it('rolls back to an earlier release with the processes the app has now', function () {
        ($this->process)('queue', ProcessKind::Queue);
        $first = ($this->deploy)();
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->deploy)();

        $rollback = app(Deployer::class)->rollback($this->shop->fresh(), $first)->fresh();

        expect($rollback->status)->toBe(DeploymentStatus::Active)
            ->and(collect($rollback->processes)->pluck('name')->sort()->values()->all())->toBe(['queue', 'scheduler'])
            ->and(($this->running)("shop-{$rollback->number}-queue"))->toBeTrue()
            ->and(($this->running)("shop-{$rollback->number}-scheduler"))->toBeTrue()
            ->and(($this->exists)('shop-2-scheduler'))->toBeFalse();
    });

    it('picks up a changed list with Apply changes, and keeps the image', function () {
        ($this->process)('queue', ProcessKind::Queue);
        $first = ($this->deploy)();
        ($this->process)('scheduler', ProcessKind::Scheduler);
        AppProcess::query()->where('name', 'queue')->update(['command' => 'php artisan queue:work --queue=mail']);

        $applied = app(Deployer::class)->apply($this->shop->fresh())->fresh();

        expect($applied->image_tag)->toBe($first->image_tag)
            ->and(($this->spec)("shop-{$applied->number}-queue")->command)->toBe(['php', 'artisan', 'queue:work', '--queue=mail'])
            ->and(($this->running)("shop-{$applied->number}-scheduler"))->toBeTrue()
            ->and(collect($this->host->actions())->where('action', 'buildImage'))->toHaveCount(1);
    });
});

describe('a gradual rollout', function () {
    beforeEach(function () {
        Queue::fake([AdvanceRolloutJob::class, WatchDeploymentJob::class]);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->process)('queue', ProcessKind::Queue);
        ($this->deploy)();
        $this->shop->update(['rollout_enabled' => true, 'rollout_steps' => [10, 50]]);
        $this->second = ($this->deploy)();
        $this->rollout = Rollout::query()->firstOrFail();
    });

    it('starts the new worker beside the old one, and leaves the scheduler to the old release until everyone has the new one', function () {
        expect($this->second->status)->toBe(DeploymentStatus::Rollout)
            ->and(($this->running)('shop-2-queue'))->toBeTrue()
            ->and(($this->exists)('shop-2-scheduler'))->toBeFalse()
            ->and(($this->running)('shop-1-scheduler'))->toBeTrue()
            ->and(($this->running)('shop-1-queue'))->toBeTrue();

        app(RolloutService::class)->complete($this->rollout, 'Promoted.');

        expect(($this->running)('shop-2-scheduler'))->toBeTrue()
            ->and(($this->exists)('shop-1-scheduler'))->toBeFalse()
            ->and(($this->exists)('shop-1-queue'))->toBeFalse()
            ->and(($this->exists)('shop-1'))->toBeFalse();
    });

    it('removes the new release\'s containers when the rollout is aborted, and the old scheduler never stops', function () {
        app(RolloutService::class)->abort($this->rollout, null, 'a person decided');

        expect(($this->exists)('shop-2'))->toBeFalse()
            ->and(($this->exists)('shop-2-queue'))->toBeFalse()
            ->and(($this->running)('shop-1-scheduler'))->toBeTrue()
            ->and(($this->running)('shop-1-queue'))->toBeTrue();
    });
});

describe('the stop-first deploy mode', function () {
    beforeEach(function () {
        $this->shop->update(['deploy_strategy' => DeployStrategy::StopFirst]);
        ($this->process)('queue', ProcessKind::Queue);
        ($this->process)('scheduler', ProcessKind::Scheduler);
    });

    it('stops every container of the old release first, and removes them without waiting', function () {
        ($this->deploy)();
        $before = count($this->host->actions());

        ($this->deploy)();
        $actions = array_slice(($this->actions)(), $before);

        expect(array_search('stopContainer shop-1-queue', $actions, true))->toBeLessThan(array_search('runContainer shop-2', $actions, true))
            ->and(array_search('stopContainer shop-1-scheduler', $actions, true))->toBeLessThan(array_search('runContainer shop-2', $actions, true))
            ->and(($this->exists)('shop-1-queue'))->toBeFalse()
            ->and(($this->running)('shop-2-scheduler'))->toBeTrue();
    });

    it('starts the old release\'s containers again when the new one fails', function () {
        ($this->deploy)();
        $this->host->exitContainersEndingWith('-queue');

        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Failed)
            ->and(($this->running)('shop-1'))->toBeTrue()
            ->and(($this->running)('shop-1-queue'))->toBeTrue()
            ->and(($this->running)('shop-1-scheduler'))->toBeTrue();
    });
});

describe('controlling the app', function () {
    beforeEach(function () {
        ($this->process)('queue', ProcessKind::Queue);
        ($this->process)('scheduler', ProcessKind::Scheduler);
        Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
        $this->live = ($this->deploy)();
    });

    it('stops, starts and restarts the web container and every process', function () {
        app(ControlApp::class)->stop($this->shop->fresh());

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler'] as $name) {
            expect(($this->running)($name))->toBeFalse($name);
        }

        app(ControlApp::class)->start($this->shop->fresh());

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler'] as $name) {
            expect(($this->running)($name))->toBeTrue($name);
        }

        $before = count($this->host->actions());
        app(ControlApp::class)->restart($this->shop->fresh());

        expect(array_slice(($this->actions)(), $before))->toBe(['restartContainer shop-1', 'restartContainer shop-1-queue', 'restartContainer shop-1-scheduler']);
    });

    it('removes every container when the app is deactivated, and starts them again when it is activated', function () {
        app(DeactivateApp::class)->deactivate($this->shop->fresh());

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler'] as $name) {
            expect(($this->exists)($name))->toBeFalse($name);
        }

        $activated = app(DeactivateApp::class)->activate($this->shop->fresh())->fresh();

        expect(($this->running)("shop-{$activated->number}-queue"))->toBeTrue()->and(($this->running)("shop-{$activated->number}-scheduler"))->toBeTrue();
    });

    it('removes the containers of every release when the app is deleted', function () {
        ($this->deploy)();

        $problems = app(DestroyApp::class)($this->shop->fresh());

        foreach (['shop-1', 'shop-1-queue', 'shop-1-scheduler', 'shop-2', 'shop-2-queue', 'shop-2-scheduler'] as $name) {
            expect(($this->exists)($name))->toBeFalse($name);
        }

        expect($problems)->toBe([]);
    });
});

describe('looking at the containers', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
        ($this->process)('queue', ProcessKind::Queue, ['replicas' => 2]);
        ($this->process)('scheduler', ProcessKind::Scheduler);
        ($this->deploy)();
    });

    it('offers the web container and each process on the logs tab, and reads the one asked for', function () {
        $response = $this->getJson('/apps/shop/logs?container=shop-1-queue-2')->assertOk();

        expect($response->json('container'))->toBe('shop-1-queue-2')
            ->and($response->json('logs'))->toContain('shop-1-queue-2')
            ->and(collect($response->json('containers'))->pluck('label')->all())->toBe(['web', 'queue 1', 'queue 2', 'scheduler']);

        // A name that is not a container of the live release means the web one.
        expect($this->getJson('/apps/shop/logs?container=db-main')->json('container'))->toBe('shop-1')
            ->and($this->getJson('/apps/shop/logs')->json('container'))->toBe('shop-1');
    });

    it('downloads the log of a process under its own name', function () {
        $response = $this->get('/apps/shop/logs/download?container=shop-1-scheduler')->assertOk();

        expect($response->headers->get('Content-Disposition'))->toContain('filename="shop-scheduler-');
    });

    it('opens a terminal in the container asked for, and only one of the live release', function () {
        $this->withSession(['auth.password_confirmed_at' => time()]);

        expect($this->postJson('/apps/shop/terminal', ['container' => 'shop-1-scheduler'])->json('container'))->toBe('shop-1-scheduler')
            ->and($this->postJson('/apps/shop/terminal', ['container' => 'db-main'])->json('container'))->toBe('shop-1');
    });

    it('shows the process containers on the usage tab, and the app they belong to on the server pages', function () {
        $usage = $this->getJson('/apps/shop/usage?container=shop-1-queue-1')->assertOk();

        expect($usage->json('container.name'))->toBe('shop-1-queue-1');

        $server = $this->getJson('/server/usage')->assertOk();
        $queue = collect($server->json('containers'))->firstWhere('name', 'shop-1-queue-1');

        expect($queue['app']['slug'])->toBe('shop')->and($queue['release'])->toBe(1);

        $own = $this->getJson('/apps/shop/containers')->assertOk();

        expect(collect($own->json('containers'))->pluck('process', 'name')->all())->toBe(['shop-1' => 'web', 'shop-1-queue-1' => 'queue', 'shop-1-queue-2' => 'queue', 'shop-1-scheduler' => 'scheduler'])
            ->and(collect($own->json('containers'))->every(fn ($c) => $c['live']))->toBeTrue();
    });
});

it('seeds the scheduler on and the detected services off, once', function () {
    $this->shop->update(['stack' => Stack::Laravel]);

    app(AppProcesses::class)->seedFor($this->shop->fresh(), [
        ['kind' => 'horizon', 'name' => 'horizon', 'detected_by' => 'laravel/horizon', 'default_command' => 'php artisan horizon', 'supported' => true, 'note' => null],
        ['kind' => 'queue', 'name' => 'queue', 'detected_by' => null, 'default_command' => 'php artisan queue:work --tries=3 --timeout=90', 'supported' => true],
        ['kind' => 'custom', 'name' => 'Reverb Server', 'detected_by' => 'laravel/reverb', 'default_command' => 'php artisan reverb:start', 'supported' => false, 'note' => 'Needs a public websocket port.'],
        ['kind' => 'nonsense', 'name' => 'web'],
    ]);

    $rows = $this->shop->processes()->get();

    expect($rows->pluck('name')->all())->toBe(['scheduler', 'horizon', 'queue', 'reverb-server', 'worker-app'])
        ->and($rows->pluck('enabled')->all())->toBe([true, false, false, false, false])
        ->and($rows->firstWhere('name', 'horizon')->command)->toBeNull()
        ->and($rows->firstWhere('name', 'horizon')->detected_by)->toBe('laravel/horizon')
        ->and($rows->firstWhere('name', 'horizon')->overlap)->toBe('stop_first')
        ->and($rows->firstWhere('name', 'queue')->overlap)->toBe('overlap')
        ->and($rows->firstWhere('name', 'reverb-server')->command)->toBe('php artisan reverb:start');

    // A second call changes nothing: the person's edits are never undone.
    $rows->firstWhere('name', 'queue')->update(['enabled' => true]);
    app(AppProcesses::class)->seedFor($this->shop->fresh(), [['kind' => 'pulse', 'name' => 'pulse']]);

    expect($this->shop->processes()->count())->toBe(5)->and($this->shop->processes()->where('name', 'queue')->value('enabled'))->toBeTruthy();
});

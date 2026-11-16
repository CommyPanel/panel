<?php

use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStatus;
use App\Enums\DeployStrategy;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\Rollout;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 5, 'commypanel.deploy.keep_images' => 3]);
    Sleep::fake();

    $this->health = new FakeHealthChecker;
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, $this->health);
    app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health', 'deploy_strategy' => DeployStrategy::StopFirst]);
    $this->deploy = fn (): Deployment => app(Deployer::class)->deploy($this->shop->refresh())->fresh();
    /** The host actions made from now on, in order, as "action name". */
    $this->actions = fn () => collect($this->host->actions())->map(fn ($a) => $a['action'].' '.($a['args']['name'] ?? ($a['args']['spec']->name ?? '')))->values()->all();
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

it('does not stop anything for the first release', function () {
    $first = ($this->deploy)();

    expect($first->status)->toBe(DeploymentStatus::Active)
        ->and($first->strategy)->toBe(DeployStrategy::StopFirst)
        ->and(($this->actions)())->not->toContain('stopContainer shop-1');
});

it('stops the old version before the new one starts, then removes it without waiting to drain', function () {
    ($this->deploy)();
    $before = count($this->host->actions());

    $second = ($this->deploy)();
    $actions = array_slice(($this->actions)(), $before);

    expect($second->status)->toBe(DeploymentStatus::Active)
        ->and($second->log)->toContain('Stopping the previous version (shop-1) first')->toContain('Removing the previous version (shop-1)')->not->toContain('in 5s')
        ->and(array_search('stopContainer shop-1', $actions, true))->toBeLessThan(array_search('runContainer shop-2', $actions, true))
        ->and(array_search('runContainer shop-2', $actions, true))->toBeLessThan(array_search('removeContainer shop-1', $actions, true))
        ->and($this->shop->refresh()->active_deployment_id)->toBe($second->id);

    Sleep::assertNeverSlept();
});

it('starts the old version again when the new one never answers', function () {
    $first = ($this->deploy)();
    $this->health->healthy = false;

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->error)->toContain('did not answer')->toContain('The previous version is running again.')
        ->and($second->log)->toContain('Starting the previous version (shop-1) again.')
        ->and(($this->actions)())->toContain('startContainer shop-1')->toContain('removeContainer shop-2')
        ->and($this->shop->refresh()->active_deployment_id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(DeploymentStatus::Active);
});

it('starts the old version again when the new container cannot be created at all', function () {
    ($this->deploy)();
    $this->host->failOn('runContainer');

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->error)->toContain('The previous version is running again.')
        ->and(($this->actions)())->toContain('startContainer shop-1');
});

it('says so plainly when the old version cannot be started again either', function () {
    ($this->deploy)();
    $this->health->healthy = false;
    $this->host->failOn('startContainer');

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->error)->toContain('could not be started again; start the app from its page');
});

it('leaves an app that a person stopped stopped, also when the deploy fails', function () {
    ($this->deploy)();
    $this->shop->refresh()->forceFill(['stopped_at' => now()])->save();
    $this->health->healthy = false;
    $before = count($this->host->actions());

    $second = ($this->deploy)();
    $actions = array_slice(($this->actions)(), $before);

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->log)->toContain('is already stopped')
        ->and($actions)->not->toContain('stopContainer shop-1')->not->toContain('startContainer shop-1');
});

it('never rolls out gradually or keeps the old version for a watch, whatever the app says', function () {
    $this->shop->update(['rollout_enabled' => true, 'watch_enabled' => true]);
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    ($this->deploy)();

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Active)
        ->and($second->watch_state)->toBeNull()
        ->and(Rollout::query()->where('app_id', $this->shop->id)->count())->toBe(0)
        ->and(($this->actions)())->toContain('removeContainer shop-1');
});

it('applies to rollbacks and to applying changed variables too', function () {
    $first = ($this->deploy)();
    ($this->deploy)();

    // The queue runs jobs at once in tests.
    $rollback = app(Deployer::class)->rollback($this->shop->refresh(), $first)->fresh();

    expect($rollback->strategy)->toBe(DeployStrategy::StopFirst)->and($rollback->status)->toBe(DeploymentStatus::Active)
        ->and($rollback->log)->toContain('Stopping the previous version');

    $apply = app(Deployer::class)->apply($this->shop->refresh())->fresh();

    expect($apply->strategy)->toBe(DeployStrategy::StopFirst)->and($apply->status)->toBe(DeploymentStatus::Active)
        ->and($apply->log)->toContain('Stopping the previous version');
});

it('keeps the mode a deployment was queued with, even if the app changes before it runs', function () {
    $queued = app(Deployer::class)->deploy($this->shop->refresh());
    $this->shop->update(['deploy_strategy' => DeployStrategy::Rolling]);

    expect($queued->fresh()->strategy)->toBe(DeployStrategy::StopFirst);
});

it('keeps zero downtime for an app that did not choose stop-first', function () {
    $this->shop->update(['deploy_strategy' => DeployStrategy::Rolling]);
    ($this->deploy)();
    $before = count($this->host->actions());

    $second = ($this->deploy)();
    $actions = array_slice(($this->actions)(), $before);

    expect($second->strategy)->toBe(DeployStrategy::Rolling)
        ->and(array_search('runContainer shop-2', $actions, true))->toBeLessThan(array_search('stopContainer shop-1', $actions, true));
});

describe('a worker that dies in the middle', function () {
    beforeEach(function () {
        $this->first = ($this->deploy)();
        $this->host->stopContainer('shop-1'); // what stop-first did before the worker died
        $this->interrupted = Deployment::factory()->create([
            'app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Starting,
            'strategy' => DeployStrategy::StopFirst, 'container_name' => 'shop-2',
        ]);
    });

    it('starts the old version again', function () {
        (new RunDeployment($this->interrupted->id))->failed(null);

        expect(($this->actions)())->toContain('startContainer shop-1')
            ->and($this->interrupted->fresh()->status)->toBe(DeploymentStatus::Failed);
    });

    it('does not, when a person had stopped the app', function () {
        $this->shop->forceFill(['stopped_at' => now()])->save();
        (new RunDeployment($this->interrupted->id))->failed(null);

        expect(($this->actions)())->not->toContain('startContainer shop-1');
    });

    it('does not, when the new version had already taken over', function () {
        $this->shop->forceFill(['active_deployment_id' => $this->interrupted->id])->save();
        (new RunDeployment($this->interrupted->id))->failed(null);

        expect(($this->actions)())->not->toContain('startContainer shop-1');
    });

    it('does not touch a zero-downtime app', function () {
        $this->interrupted->update(['strategy' => DeployStrategy::Rolling]);
        (new RunDeployment($this->interrupted->id))->failed(null);

        expect(($this->actions)())->not->toContain('startContainer shop-1');
    });
});

it('moves existing Redis, Garage and Elasticsearch apps to stop-first when the panel is upgraded', function () {
    $migration = require database_path('migrations/2026_11_16_120000_add_deploy_strategy.php');
    $migration->down();

    foreach (['redis', 'garage', 'elasticsearch', 'meilisearch', null] as $template) {
        App::factory()->create(['slug' => 'app-'.($template ?? 'plain'), 'template' => $template]);
    }

    $migration->up();

    $modes = DB::table('apps')->pluck('deploy_strategy', 'slug')->all();
    expect($modes['app-redis'])->toBe('stop_first')->and($modes['app-garage'])->toBe('stop_first')->and($modes['app-elasticsearch'])->toBe('stop_first')
        ->and($modes['app-meilisearch'])->toBe('rolling')->and($modes['app-plain'])->toBe('rolling');
});

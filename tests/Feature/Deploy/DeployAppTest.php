<?php

use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStage;
use App\Enums\DeploymentStatus;
use App\Enums\Stack;
use App\Host\Data\ImageBuildSpec;
use App\Host\Data\TaskSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config([
        'commypanel.data_path' => $this->dataPath,
        'commypanel.deploy.drain_seconds' => 0,
        'commypanel.deploy.keep_images' => 3,
    ]);
    Sleep::fake();

    $this->fetcher = new FakeSourceFetcher;
    $this->health = new FakeHealthChecker;
    $this->traffic = new RecordingTrafficSwitcher;
    $this->host = app(HostDriver::class);

    app()->instance(SourceFetcher::class, $this->fetcher);
    app()->instance(HealthChecker::class, $this->health);
    app()->instance(TrafficSwitcher::class, $this->traffic);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);

    $this->deploy = fn (): Deployment => app(Deployer::class)->deploy($this->shop)->fresh();
});

afterEach(function () {
    File::deleteDirectory($this->dataPath);
});

/** @return list<string> */
function hostActions(): array
{
    return app(HostDriver::class)->actionNames();
}

it('deploys a new app end to end', function () {
    AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'DB_HOST', 'value' => 'db']);

    $deployment = ($this->deploy)();

    expect($deployment->status)->toBe(DeploymentStatus::Active)
        ->and($deployment->number)->toBe(1)
        ->and($deployment->commit_sha)->toBe('abc123def456')
        ->and($deployment->image_tag)->toBe('commypanel/shop:1')
        ->and($deployment->container_name)->toBe('shop-1')
        ->and($deployment->host_port)->toBeBetween(20000, 29999)
        ->and($deployment->started_at)->not->toBeNull()
        ->and($deployment->finished_at)->not->toBeNull()
        ->and($deployment->log)->toContain('Detected Go 1.23')->toContain('Deployment finished.');

    expect($this->shop->fresh()->stack)->toBe(Stack::Go)
        ->and($this->shop->fresh()->active_deployment_id)->toBe($deployment->id)
        ->and(hostActions())->toBe(['buildImage', 'runContainer'])
        ->and($this->traffic->switched)->toBe([$deployment->id])
        ->and($this->health->calls)->toBe([['port' => $deployment->host_port, 'path' => '/health']]);

    $container = $this->host->containers()['shop-1'];
    expect($container->env)->toBe(['PORT' => '8080', 'DB_HOST' => 'db'])
        ->and($container->hostPort)->toBe($deployment->host_port)
        ->and($container->containerPort)->toBe(8080);
});

it('builds with a generated Dockerfile and never touches the repository one', function () {
    $dockerfiles = [];
    $spy = new class($dockerfiles) extends FakeHostDriver
    {
        public function __construct(public array &$seen) {}

        public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
        {
            $this->seen[] = [$spec->dockerfile, file_get_contents($spec->contextPath.'/'.$spec->dockerfile)];
            parent::buildImage($spec, $onOutput);
        }
    };
    app()->instance(HostDriver::class, $spy);

    ($this->deploy)();

    expect($dockerfiles)->toHaveCount(1)
        ->and($dockerfiles[0][0])->toBe('Dockerfile.commypanel')
        ->and($dockerfiles[0][1])->toContain('FROM golang:1.23');
});

it('uses the repository Dockerfile when there is one', function () {
    $this->fetcher->files = ['Dockerfile' => "FROM scratch\n", 'go.mod' => "module x\n\ngo 1.23\n"];
    $names = [];
    $spy = new class($names) extends FakeHostDriver
    {
        public function __construct(public array &$seen) {}

        public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
        {
            $this->seen[] = $spec->dockerfile;
            parent::buildImage($spec, $onOutput);
        }
    };
    app()->instance(HostDriver::class, $spy);

    $deployment = ($this->deploy)();

    expect($names)->toBe(['Dockerfile'])
        ->and($this->shop->fresh()->stack)->toBe(Stack::Dockerfile)
        ->and($deployment->status)->toBe(DeploymentStatus::Active);
});

it('starts the new version before stopping the old one', function () {
    $first = ($this->deploy)();
    $second = ($this->deploy)();

    expect(hostActions())->toBe([
        'buildImage', 'runContainer',
        'buildImage', 'runContainer', 'stopContainer', 'removeContainer',
    ])
        ->and($first->fresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($second->status)->toBe(DeploymentStatus::Active)
        ->and($this->shop->fresh()->active_deployment_id)->toBe($second->id)
        ->and($this->host->containers())->toHaveKey('shop-2')->not->toHaveKey('shop-1')
        ->and($first->host_port)->not->toBe($second->host_port);
});

it('keeps the old version serving when the build fails', function () {
    $first = ($this->deploy)();
    $this->host->failOn('buildImage');

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->error)->toContain('Fake failure')
        ->and($second->log)->toContain('Deployment failed')
        ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(DeploymentStatus::Active)
        ->and($this->host->containers())->toHaveKey('shop-1')
        ->and(hostActions())->not->toContain('stopContainer')
        ->and($this->traffic->switched)->toBe([$first->id]);
});

it('removes the new container and keeps the old one when the health check fails', function () {
    $first = ($this->deploy)();
    $this->health->healthy = false;

    $second = ($this->deploy)();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($second->error)->toContain('did not answer')
        ->and($second->log)->toContain('container output')
        ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
        ->and($first->fresh()->status)->toBe(DeploymentStatus::Active)
        ->and($this->host->containers())->toHaveKey('shop-1')->not->toHaveKey('shop-2')
        ->and($this->traffic->switched)->toBe([$first->id]);
});

describe('release command', function () {
    it('runs it once from the new image with the app environment before starting the container', function () {
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'DB_HOST', 'value' => 'db']);
        $this->shop->update(['release_command' => "sh -c 'migrate && seed'", 'memory_limit_mb' => 256]);

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Active)
            ->and($deployment->release_command)->toBe("sh -c 'migrate && seed'")
            ->and(hostActions())->toBe(['buildImage', 'runTask', 'runContainer'])
            ->and($deployment->log)->toContain('Running the release command')
            ->toContain('[fake] running sh -c migrate && seed')
            ->toContain('The release command finished.');

        /** @var TaskSpec $task */
        $task = collect($this->host->actions())->firstWhere('action', 'runTask')['args']['spec'];
        expect($task->name)->toBe('shop-1-release')
            ->and($task->image)->toBe('commypanel/shop:1')
            ->and($task->command)->toBe(['sh', '-c', 'migrate && seed'])
            ->and($task->env)->toBe(['PORT' => '8080', 'DB_HOST' => 'db'])
            ->and($task->memoryLimitMb)->toBe(256)
            ->and($task->timeoutSeconds)->toBe(600);
    });

    it('keeps the old version serving and never starts the new one when it fails', function () {
        $first = ($this->deploy)();
        $this->shop->update(['release_command' => 'npm run migrate']);
        $this->host->taskExitsWith(1);

        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Failed)
            ->and($second->stage)->toBe(DeploymentStage::Release)
            ->and($second->error)->toBe('The release command failed with exit code 1. The new version was not started.')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
            ->and($this->host->containers())->toHaveKey('shop-1')->not->toHaveKey('shop-2')
            ->and($this->traffic->switched)->toBe([$first->id]);
    });

    it('fails the deployment when the agent cannot run it', function () {
        $this->shop->update(['release_command' => 'npm run migrate']);
        $this->host->failOn('runTask');

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Failed)
            ->and($deployment->error)->toContain('Fake failure')
            ->and(hostActions())->not->toContain('runContainer');
    });

    it('is not run again by a rollback', function () {
        $this->shop->update(['release_command' => 'npm run migrate']);
        $first = ($this->deploy)();
        ($this->deploy)();

        $rollback = app(Deployer::class)->rollback($this->shop->fresh(), $first)->fresh();

        expect($rollback->status)->toBe(DeploymentStatus::Active)
            ->and($rollback->release_command)->toBeNull()
            ->and(collect(hostActions())->filter(fn ($a) => $a === 'runTask'))->toHaveCount(2);
    });
});

it('clears the stopped state once a new version is live', function () {
    ($this->deploy)();
    $this->shop->update(['stopped_at' => now()]);

    ($this->deploy)();

    expect($this->shop->fresh()->stopped_at)->toBeNull();
});

it('fails clearly when the source is not a supported project', function () {
    $this->fetcher->files = ['README.md' => 'hello'];

    $deployment = ($this->deploy)();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toContain('Could not detect the app type')
        ->and(hostActions())->toBe([]);
});

it('reports Docker being unavailable without a stack trace', function () {
    $host = new class extends FakeHostDriver
    {
        public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
        {
            throw new DockerUnavailable('Agent error: docker is not available');
        }
    };
    app()->instance(HostDriver::class, $host);

    $deployment = ($this->deploy)();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toBe('Docker is not available on this server.');
});

it('hides unexpected exception details from the deployment log', function () {
    $this->fetcher->throw = new RuntimeException('SECRET internal path /var/lib/x');

    $deployment = ($this->deploy)();

    expect($deployment->status)->toBe(DeploymentStatus::Failed)
        ->and($deployment->error)->toBe('An unexpected error occurred. See the panel log for details.')
        ->and($deployment->log)->not->toContain('SECRET');
});

it('cleans the build directory after success and failure', function () {
    ($this->deploy)();
    $this->health->healthy = false;
    ($this->deploy)();

    expect(glob($this->dataPath.'/builds/*'))->toBe([]);
});

it('records audit entries for the outcome', function () {
    ($this->deploy)();
    $this->health->healthy = false;
    ($this->deploy)();

    $entries = AuditLog::query()->orderBy('id')->get(['action', 'metadata']);

    expect($entries->pluck('action')->all())->toBe(['deploy.queued', 'deploy.succeeded', 'deploy.queued', 'deploy.failed'])
        ->and($entries[3]->metadata)->toMatchArray(['app' => 'shop', 'deployment' => 2]);
});

it('removes old release images but keeps the newest ones', function () {
    config(['commypanel.deploy.keep_images' => 2]);

    $first = ($this->deploy)();
    ($this->deploy)();
    ($this->deploy)();

    $removed = collect($this->host->actions())->where('action', 'removeImage')->pluck('args.tag')->all();

    expect($removed)->toBe(['commypanel/shop:1'])
        ->and($first->fresh()->image_available)->toBeFalse()
        ->and(Deployment::query()->where('number', 3)->first()->image_available)->toBeTrue();
});

it('rolls back to an earlier release without rebuilding', function () {
    $first = ($this->deploy)();
    $second = ($this->deploy)();

    $before = count(collect($this->host->actions())->where('action', 'buildImage'));
    $rollback = app(Deployer::class)->rollback($this->shop->fresh(), $first)->fresh();

    expect($rollback->status)->toBe(DeploymentStatus::Active)
        ->and($rollback->number)->toBe(3)
        ->and($rollback->image_tag)->toBe('commypanel/shop:1')
        ->and($rollback->rolled_back_from_id)->toBe($first->id)
        ->and(count(collect($this->host->actions())->where('action', 'buildImage')))->toBe($before)
        ->and($second->fresh()->status)->toBe(DeploymentStatus::Superseded)
        ->and($this->shop->fresh()->active_deployment_id)->toBe($rollback->id)
        ->and($this->host->containers())->toHaveKey('shop-3')->not->toHaveKey('shop-2')
        ->and($rollback->log)->toContain('Rolling back');
});

it('refuses rollbacks that cannot work', function () {
    $first = ($this->deploy)();
    $second = ($this->deploy)();
    $other = Deployment::factory()->create(['image_tag' => 'commypanel/other:1']);

    $deployer = app(Deployer::class);
    $app = $this->shop->fresh();

    expect(fn () => $deployer->rollback($app, $second))->toThrow(DeploymentFailed::class, 'already live')
        ->and(fn () => $deployer->rollback($app, $other))->toThrow(DeploymentFailed::class, 'no longer available');

    $first->forceFill(['image_available' => false])->save();
    expect(fn () => $deployer->rollback($app, $first->fresh()))->toThrow(DeploymentFailed::class, 'no longer available');
});

describe('stages', function () {
    it('ends at done after a successful deployment', function () {
        expect(($this->deploy)()->stage)->toBe(DeploymentStage::Done);
    });

    it('stays on the step that failed', function (string $break, DeploymentStage $expected) {
        match ($break) {
            'fetch' => $this->fetcher->files = ['README.md' => 'nothing buildable'],
            'build' => $this->host->failOn('buildImage'),
            'start' => $this->host->failOn('runContainer'),
            'health' => $this->health->healthy = false,
            'switch' => $this->traffic->throw = new HostOperationFailed('nginx -t failed'),
        };

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Failed)
            ->and($deployment->stage)->toBe($expected);
    })->with([
        'repository cannot be built' => ['fetch', DeploymentStage::Fetch],
        'image build fails' => ['build', DeploymentStage::Build],
        'container does not start' => ['start', DeploymentStage::Start],
        'health check fails' => ['health', DeploymentStage::Health],
        'traffic cannot be switched' => ['switch', DeploymentStage::Switch],
    ]);

    it('reports the stage to the deployment page', function () {
        $deployment = ($this->deploy)();
        $this->actingAs(User::factory()->create());

        $this->get("/apps/shop/deployments/{$deployment->number}")
            ->assertInertia(fn ($page) => $page->where('deployment.stage', 'done'));
    });
});

<?php

use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Stacks\Laravel\InspectSource;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStatus;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\Host\Data\ImageBuildSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\InspectSourceJob;
use App\Models\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

/** @return array<string, string> */
function sampleLaravel(array $over = []): array
{
    return [
        'artisan' => '<?php',
        'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0']]),
        'composer.lock' => json_encode(['packages' => [['name' => 'laravel/framework', 'version' => 'v12.1.0'], ['name' => 'laravel/horizon', 'version' => 'v5.30.0']]]),
        'bootstrap/app.php' => "<?php\n->withRouting(health: '/up')\n",
        'package.json' => json_encode(['scripts' => ['build' => 'vite build']]),
        '.env.example' => "APP_NAME=Laravel\nDB_HOST=127.0.0.1\n",
        ...$over,
    ];
}

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    $this->fetcher = new FakeSourceFetcher(sampleLaravel());
    app()->instance(SourceFetcher::class, $this->fetcher);
    $this->draft = App::factory()->create(['slug' => 'shop', 'setup_step' => SetupStep::Settings, 'stack_hint' => null]);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

describe('looking at the code in the wizard', function () {
    it('stores what it found, and chooses Laravel when the person chose nothing', function () {
        app(InspectSource::class)($this->draft);

        $app = $this->draft->fresh();

        expect($app->detected['stack'])->toBe('laravel')
            ->and($app->detected['laravel_major'])->toBe(12)
            ->and($app->detected['health_path'])->toBe('/up')
            ->and(collect($app->detected['services'])->pluck('kind')->all())->toBe(['scheduler', 'queue', 'horizon'])
            ->and($app->detected['env_hints'])->toBe(['APP_NAME', 'DB_HOST'])
            ->and($app->detected['detected_at'])->not->toBeNull()
            ->and($app->stack_hint)->toBe(Stack::Laravel)
            ->and(File::isDirectory("{$this->dataPath}/builds/inspect-shop"))->toBeFalse();
    });

    it('keeps the type the person chose', function () {
        $this->draft->update(['stack_hint' => Stack::Node]);

        app(InspectSource::class)($this->draft);

        expect($this->draft->fresh()->stack_hint)->toBe(Stack::Node)->and($this->draft->fresh()->detected['stack'])->toBe('laravel');
    });

    it('says when the code is not a Laravel app, and leaves the type alone', function () {
        $this->fetcher->files = ['go.mod' => "module x\n", 'main.go' => 'package main'];

        app(InspectSource::class)($this->draft);

        $app = $this->draft->fresh();

        expect($app->detected['stack'])->toBeNull()->and($app->detected)->not->toHaveKey('error')->and($app->stack_hint)->toBeNull();
    });

    it('stores the fetcher\'s sentence when the code cannot be fetched, and cleans up', function () {
        $this->fetcher->throw = new DeploymentFailed('Could not fetch the repository: Permission denied (publickey).');

        app(InspectSource::class)($this->draft);

        expect($this->draft->fresh()->detected['error'])->toBe('Could not fetch the repository: Permission denied (publickey).')
            ->and(File::isDirectory("{$this->dataPath}/builds/inspect-shop"))->toBeFalse();
    });

    it('does not show an unexpected error\'s text', function () {
        $this->fetcher->throw = new RuntimeException('/var/lib/commypanel/secret/path exploded');

        app(InspectSource::class)($this->draft);

        expect($this->draft->fresh()->detected['error'])->toContain('could not be inspected')->not->toContain('secret');
    });

    it('only looks at a draft, and never at a ready-made app', function () {
        $app = App::factory()->create(['slug' => 'done', 'setup_step' => null]);
        $recipe = App::factory()->create(['slug' => 'kuma', 'setup_step' => SetupStep::Domain, 'source_type' => SourceType::Template]);

        app(InspectSource::class)($app);
        app(InspectSource::class)($recipe);

        expect($app->fresh()->detected)->toBeNull()->and($recipe->fresh()->detected)->toBeNull();
    });

    it('queues a new look and forgets the old answer, so the wizard can tell it is pending', function () {
        Queue::fake();
        $this->draft->update(['detected' => ['stack' => 'laravel', 'detected_at' => now()->toIso8601String()]]);

        InspectSource::queue($this->draft);

        expect($this->draft->fresh()->detected)->toBeNull();
        Queue::assertPushed(InspectSourceJob::class, fn ($job) => $job->appId === $this->draft->id);
    });

    it('runs from the queue, and gives up with an answer instead of leaving the wizard waiting', function () {
        InspectSource::queue($this->draft);

        expect($this->draft->fresh()->detected['stack'])->toBe('laravel');

        App::query()->whereKey($this->draft->id)->update(['detected' => null]);
        (new InspectSourceJob($this->draft->id))->failed(new RuntimeException('timeout'));

        expect($this->draft->fresh()->detected['error'])->toContain('too long');

        // A finished answer is never overwritten by a late failure.
        $this->draft->fresh()->update(['detected' => ['stack' => 'laravel', 'detected_at' => 'x']]);
        (new InspectSourceJob($this->draft->id))->failed(null);

        expect($this->draft->fresh()->detected['stack'])->toBe('laravel');
    });
});

describe('deploying a Laravel app', function () {
    beforeEach(function () {
        // A host that remembers what the build folder held, because it is removed after the deployment.
        $this->host = new class extends FakeHostDriver
        {
            /** @var array<string, string> */
            public array $context = [];

            public function buildImage(ImageBuildSpec $spec, ?callable $onOutput = null): void
            {
                foreach (['Dockerfile.commypanel', '.dockerignore', '.commypanel/nginx.conf', '.commypanel/php-fpm.conf', '.commypanel/entrypoint.sh', '.commypanel/php.ini'] as $file) {
                    if (is_file("{$spec->contextPath}/{$file}")) {
                        $this->context[$file] = (string) file_get_contents("{$spec->contextPath}/{$file}");
                    }
                }

                parent::buildImage($spec, $onOutput);
            }
        };
        app()->instance(HostDriver::class, $this->host);
        app()->instance(HealthChecker::class, new FakeHealthChecker);
        app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

        $this->shop = App::factory()->create(['slug' => 'blog', 'stack' => null, 'container_port' => 8080, 'health_path' => '/up']);
        $this->deploy = fn () => app(Deployer::class)->deploy($this->shop)->fresh();
    });

    it('builds the generated image with the files the stack asks for next to the Dockerfile', function () {
        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Active)
            ->and($this->shop->fresh()->stack)->toBe(Stack::Laravel)
            ->and(array_keys($this->host->context))->toContain('Dockerfile.commypanel', '.dockerignore', '.commypanel/nginx.conf', '.commypanel/php-fpm.conf', '.commypanel/entrypoint.sh', '.commypanel/php.ini')
            ->and($this->host->context['Dockerfile.commypanel'])->toContain('FROM php:8.4-fpm-bookworm')->toContain('ENV PORT=8080')
            ->and($this->host->context['.commypanel/nginx.conf'])->toContain('location = /index.php');
    });

    it('hands the app\'s stack options to the build', function () {
        $this->shop->update(['stack_options' => ['php' => '8.3', 'node_manager' => 'none']]);

        ($this->deploy)();

        expect($this->host->context['Dockerfile.commypanel'])->toContain('FROM php:8.3-fpm-bookworm')->not->toContain('AS assets');
    });

    it('lets the repository\'s own .dockerignore keep its rules but never hide the stack\'s files', function () {
        $this->fetcher = new FakeSourceFetcher(sampleLaravel(['.dockerignore' => "*\n!app\n"]));
        app()->instance(SourceFetcher::class, $this->fetcher);

        ($this->deploy)();

        expect($this->host->context['.dockerignore'])->toStartWith("*\n!app\n")->toContain('!.commypanel/');
    });

    it('does not add rules to a .dockerignore of its own making twice, nor for a stack with no extra files', function () {
        $this->fetcher = new FakeSourceFetcher(['go.mod' => "module x\n\ngo 1.23\n", 'main.go' => 'package main', '.dockerignore' => "bin\n"]);
        app()->instance(SourceFetcher::class, $this->fetcher);

        ($this->deploy)();

        expect($this->host->context['.dockerignore'] ?? '')->not->toContain('.commypanel');
    });

    it('fails clearly when the PHP version is one nobody can build', function () {
        $this->fetcher = new FakeSourceFetcher(sampleLaravel(['composer.json' => json_encode(['require' => ['php' => '^7.4', 'laravel/framework' => '^8.0']])]));
        app()->instance(SourceFetcher::class, $this->fetcher);

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Failed)->and($deployment->error)->toContain('asks for PHP ^7.4');
    });
});

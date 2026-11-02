<?php

use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Host\Data\ContainerSpec;
use App\Host\Data\TaskSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();

    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, new FakeHealthChecker);
    app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
    $this->blog = App::factory()->create(['slug' => 'blog', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

it('names an app\'s network after the app', function () {
    expect($this->shop->networkName())->toBe('commypanel-shop')
        ->and($this->blog->networkName())->toBe('commypanel-blog');
});

it('keeps the longest possible app name within Docker\'s limit for network names', function () {
    $app = App::factory()->create(['slug' => str_repeat('a', App::SLUG_MAX_LENGTH)]);

    expect(strlen($app->networkName()))->toBeLessThanOrEqual(63);
});

it('starts every container on its own app\'s network', function () {
    app(Deployer::class)->deploy($this->shop);
    app(Deployer::class)->deploy($this->blog);

    $networks = collect($this->host->actions())->where('action', 'runContainer')
        ->mapWithKeys(fn ($a) => [$a['args']['spec']->name => $a['args']['spec']->network]);

    expect($networks->all())->toBe(['shop-1' => 'commypanel-shop', 'blog-1' => 'commypanel-blog']);
});

it('runs the release command on the same network as the app', function () {
    $this->shop->update(['release_command' => 'npm run migrate']);

    app(Deployer::class)->deploy($this->shop);

    /** @var TaskSpec $task */
    $task = collect($this->host->actions())->firstWhere('action', 'runTask')['args']['spec'];

    expect($task->network)->toBe('commypanel-shop');
});

it('puts the old and the new release of an app on the same network, so a switch needs no change', function () {
    app(Deployer::class)->deploy($this->shop);
    app(Deployer::class)->deploy($this->shop);

    $networks = collect($this->host->actions())->where('action', 'runContainer')->map(fn ($a) => $a['args']['spec']->network)->unique()->values()->all();

    expect($networks)->toBe(['commypanel-shop']);
});

it('puts two apps on different networks, so nothing is shared', function () {
    app(Deployer::class)->deploy($this->shop);
    app(Deployer::class)->deploy($this->blog);

    $networks = collect($this->host->listNetworks())->keyBy('name');

    expect($networks->keys()->all())->toContain('commypanel-shop', 'commypanel-blog')
        ->and(collect($networks['commypanel-shop']->containers)->pluck('name')->all())->toBe(['shop-1'])
        ->and(collect($networks['commypanel-blog']->containers)->pluck('name')->all())->toBe(['blog-1']);
});

it('does not warn about a shared network once the apps are on their own', function () {
    app(Deployer::class)->deploy($this->shop);
    app(Deployer::class)->deploy($this->blog);

    $json = $this->getJson('/apps/shop/containers')->assertOk()->json();

    expect(collect($json['networks'])->pluck('name')->all())->toBe(['commypanel-shop'])
        ->and(collect($json['networks'][0]['containers'])->pluck('app.slug')->unique()->all())->toBe(['shop']);
});

describe('deleting an app', function () {
    it('removes its network after its containers', function () {
        Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'container_name' => 'shop-1']);
        $this->host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080, network: 'commypanel-shop'));

        $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

        $names = $this->host->actionNames();
        $removed = collect($this->host->actions())->firstWhere('action', 'removeNetwork');

        expect($removed['args'])->toBe(['name' => 'commypanel-shop'])
            ->and(array_search('removeContainer', $names, true))->toBeLessThan(array_search('removeNetwork', $names, true));
    });

    it('still deletes the app, with a warning, when the network cannot be removed', function () {
        $host = new class extends FakeHostDriver
        {
            public function removeNetwork(string $name): void
            {
                throw new HostOperationFailed('network commypanel-shop still has containers attached');
            }
        };
        app()->instance(HostDriver::class, $host);

        $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps')->assertSessionHas('warning');

        expect(App::query()->where('slug', 'shop')->exists())->toBeFalse();
    });
});

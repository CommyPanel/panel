<?php

use App\Databases\AppLinks;
use App\Databases\DatabaseServers;
use App\Databases\ManageDatabases;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DatabaseEngine;
use App\Host\Data\ContainerSpec;
use App\Host\Data\TaskSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
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

    // With the synchronous queue the server starts as soon as it is created.
    $server = app(DatabaseServers::class)->create('Main', DatabaseEngine::Postgres, '17');
    $this->database = app(ManageDatabases::class)->create($server->fresh(), 'shop');

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

function startedSpec(): ContainerSpec
{
    return collect(app(HostDriver::class)->actions())->where('action', 'runContainer')->last()['args']['spec'];
}

it('starts the app on its own network, plus the link network of each link', function () {
    $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');

    app(Deployer::class)->deploy($this->shop);

    $spec = startedSpec();

    expect($spec->network)->toBe('commypanel-shop')
        ->and(collect($spec->networks)->pluck('name')->all())->toBe([$link->networkName()]);
});

it('gives the app the connection as environment variables', function () {
    app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');

    app(Deployer::class)->deploy($this->shop);

    expect(startedSpec()->env)->toMatchArray([
        'PORT' => '8080',
        'DATABASE_HOST' => 'main',
        'DATABASE_PORT' => '5432',
        'DATABASE_NAME' => 'shop',
        'DATABASE_USER' => 'shop',
        'DATABASE_PASSWORD' => $this->database->password,
        'DATABASE_URL' => "postgresql://shop:{$this->database->password}@main:5432/shop",
    ]);
});

it('lets the app\'s own variable of the same name win, on purpose', function () {
    app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
    AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'DATABASE_HOST', 'value' => 'my-own-host']);

    app(Deployer::class)->deploy($this->shop);

    expect(startedSpec()->env['DATABASE_HOST'])->toBe('my-own-host')
        ->and(startedSpec()->env['DATABASE_NAME'])->toBe('shop');
});

it('lets the release command reach the database too', function () {
    $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
    $this->shop->update(['release_command' => 'npm run migrate']);

    app(Deployer::class)->deploy($this->shop);

    /** @var TaskSpec $task */
    $task = collect($this->host->actions())->firstWhere('action', 'runTask')['args']['spec'];

    expect($task->network)->toBe('commypanel-shop')
        ->and(collect($task->networks)->pluck('name')->all())->toBe([$link->networkName()])
        ->and($task->env)->toHaveKey('DATABASE_URL');
});

it('gives a provider app its name on the link network only once it has traffic', function () {
    $api = App::factory()->create(['slug' => 'api', 'stack' => null, 'container_port' => 3000, 'health_path' => '/health']);
    $link = app(AppLinks::class)->linkApp($this->shop, $api, 'API');

    app(Deployer::class)->deploy($api);

    expect(startedSpec()->name)->toBe('api-1')
        ->and(startedSpec()->networks)->toBe([])
        ->and($this->host->connections()[$link->networkName()])->toBe(['api-1' => ['api']]);
});

it('moves the name from the old release to the new one, so only one answers to it', function () {
    $api = App::factory()->create(['slug' => 'api', 'stack' => null, 'container_port' => 3000, 'health_path' => '/health']);
    $link = app(AppLinks::class)->linkApp($this->shop, $api, 'API');

    app(Deployer::class)->deploy($api);
    app(Deployer::class)->deploy($api->fresh());

    $names = $this->host->connections()[$link->networkName()];

    expect($names)->toHaveKey('api-2')
        ->and($names['api-2'])->toBe(['api'])
        ->and($names)->not->toHaveKey('api-1');
});

it('puts an app without links on its own network only', function () {
    app(Deployer::class)->deploy($this->shop);

    expect(startedSpec()->networks)->toBe([]);
});

it('keeps two apps apart even when both use the same database', function () {
    $blog = App::factory()->create(['slug' => 'blog', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
    $other = app(ManageDatabases::class)->create($this->database->server, 'blog');
    app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');
    app(AppLinks::class)->linkDatabase($blog, $other, 'DATABASE');

    app(Deployer::class)->deploy($this->shop);
    app(Deployer::class)->deploy($blog);

    $networks = collect($this->host->listNetworks())->keyBy('name');
    $shared = collect($networks)->filter(fn ($n) => in_array('shop-1', array_column($n->containers, 'name'), true) && in_array('blog-1', array_column($n->containers, 'name'), true));

    expect($shared)->toHaveCount(0);
});

it('removes the links, and lets the database go, when the app is deleted', function () {
    Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'container_name' => 'shop-1']);
    $link = app(AppLinks::class)->linkDatabase($this->shop, $this->database, 'DATABASE');

    $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

    expect(App::query()->where('slug', 'shop')->exists())->toBeFalse()
        ->and($this->database->links()->count())->toBe(0)
        ->and($this->host->connections())->not->toHaveKey($link->networkName())
        ->and($this->database->fresh())->not->toBeNull();
});

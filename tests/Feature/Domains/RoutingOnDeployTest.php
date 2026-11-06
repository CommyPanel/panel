<?php

use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Enums\DeploymentStatus;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Domain;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();

    $this->health = new FakeHealthChecker;
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, $this->health);

    $this->host = app(HostDriver::class);
    $this->shop = App::factory()->create(['slug' => 'shop']);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

it('routes the domains of an app to its new container when a deployment goes live', function () {
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);

    $deployment = app(Deployer::class)->deploy($this->shop)->fresh();

    $site = $this->host->sites()['shop'];
    expect($deployment->status)->toBe(DeploymentStatus::Active)
        ->and($site->domains)->toBe(['shop.example.com'])
        ->and($site->upstreamPort)->toBe($deployment->host_port);
});

it('moves traffic to the new container on every deploy, before stopping the old one', function () {
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    $this->shop->update(['watch_enabled' => false]); // a watched release keeps the old container instead of stopping it

    $first = app(Deployer::class)->deploy($this->shop)->fresh();
    $second = app(Deployer::class)->deploy($this->shop->fresh())->fresh();

    expect($this->host->sites()['shop']->upstreamPort)->toBe($second->host_port)
        ->and($second->host_port)->not->toBe($first->host_port);

    $names = $this->host->actionNames();
    expect(array_search('applyNginxSite', array_slice($names, 3), true))->toBeLessThan(array_search('stopContainer', array_slice($names, 3), true));
});

it('keeps routing to the old container when the new one is unhealthy', function () {
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    $first = app(Deployer::class)->deploy($this->shop)->fresh();

    $this->health->healthy = false;
    app(Deployer::class)->deploy($this->shop->fresh());

    expect($this->host->sites()['shop']->upstreamPort)->toBe($first->host_port);
});

it('fails the deployment and keeps the old version when nginx cannot be updated', function () {
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    $first = app(Deployer::class)->deploy($this->shop)->fresh();

    $this->host->failOn('applyNginxSite');
    $second = app(Deployer::class)->deploy($this->shop->fresh())->fresh();

    expect($second->status)->toBe(DeploymentStatus::Failed)
        ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
        ->and($this->host->containers())->toHaveKey('shop-1')->not->toHaveKey('shop-2');
});

it('does not create a site for an app without domains', function () {
    app(Deployer::class)->deploy($this->shop);

    expect($this->host->sites())->toBe([]);
});

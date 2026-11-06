<?php

use App\Api\TokenAccess;
use App\Auth\SyncRolesAndPermissions;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Enums\DeploymentStatus;
use App\Host\Data\TrafficStat;
use App\Host\HostDriver;
use App\Jobs\AdvanceRolloutJob;
use App\Jobs\SendDeployAlert;
use App\Jobs\WatchDeploymentJob;
use App\Models\App;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    Queue::fake([WatchDeploymentJob::class, AdvanceRolloutJob::class, SendDeployAlert::class]);
    app(SyncRolesAndPermissions::class)();
    Carbon::setTestNow('2026-11-07 12:00:00');
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, new FakeHealthChecker);
    $this->host = app(HostDriver::class);
    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $this->shop->id]);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->deploy = fn () => app(Deployer::class)->deploy($this->shop->fresh())->fresh();
    $this->first = ($this->deploy)();
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->dataPath);
});

it('puts the settings of safe deployments on the app page', function () {
    $this->shop->update(['rollout_steps' => [25, 75], 'watch_minutes' => 8]);

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
        ->where('app.safe_deploys', ['watch_enabled' => true, 'watch_minutes' => 8, 'rollout_enabled' => false, 'rollout_steps' => [25, 75], 'rollout_hold_minutes' => 5, 'rollout_auto' => true]));
});

it('shows a watch on the deployment page with the new release set against the old one', function () {
    $second = ($this->deploy)();
    $this->host->useTraffic(fn () => [
        $this->first->host_port => new TrafficStat(2000, 20, 20, 100, 300),
        $second->host_port => new TrafficStat(150, 2, 25, 120, 300),
    ]);

    $this->get("/apps/shop/deployments/{$second->number}")->assertInertia(fn (Assert $page) => $page
        ->where('deployment.watch.state', 'watching')
        ->where('safe.rollout', null)
        ->where('safe.traffic.previous_number', $this->first->number)
        ->where('safe.traffic.new.requests', 150)
        ->where('safe.traffic.new.error_percent', 1.3)
        ->where('safe.traffic.reference.requests', 2000)
        ->where('safe.traffic.verdict.state', 'ok'));
});

it('shows a rollout on the deployment page, and a banner on the app page', function () {
    $this->shop->update(['rollout_enabled' => true]);
    $new = ($this->deploy)();

    $this->get("/apps/shop/deployments/{$new->number}")->assertInertia(fn (Assert $page) => $page
        ->where('deployment.status', 'rollout')
        ->where('safe.rollout.state', 'running')
        ->where('safe.rollout.percent', 10)
        ->where('safe.rollout.steps', [10, 50])
        ->where('safe.rollout.previous_number', $this->first->number)
        ->where('safe.traffic.verdict.state', 'unknown'));

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('deployments.0.status', 'rollout'));
});

it('has nothing to show for an ordinary deployment', function () {
    $this->get("/apps/shop/deployments/{$this->first->number}")->assertInertia(fn (Assert $page) => $page
        ->where('safe.rollout', null)->where('safe.traffic', null)->where('deployment.watch', null));
});

it('tells the Usage tab how each release has been doing over the last half hour', function () {
    $second = ($this->deploy)();
    $this->host->useTraffic(fn () => [
        $this->first->host_port => new TrafficStat(100, 1, 10, 50, 100, [['t' => 1, 'requests' => 100, 'errors' => 1]]),
        $second->host_port => new TrafficStat(40, 0, 10, 60, 100, [['t' => 1, 'requests' => 40, 'errors' => 0]]),
        29999 => new TrafficStat(5, 0), // a port no release of the app owns
    ]);

    $json = $this->getJson('/apps/shop/traffic')->assertOk()->json();

    expect($json['releases'])->toHaveCount(2)
        ->and($json['releases'][0])->toMatchArray(['number' => $second->number, 'live' => true, 'requests' => 40, 'errors' => 0])
        ->and($json['releases'][1])->toMatchArray(['number' => $this->first->number, 'live' => false, 'requests' => 100, 'error_percent' => 1.0])
        ->and($json['releases'][0]['series'])->toBe([['t' => 1, 'requests' => 40, 'errors' => 0]])
        ->and($json['step'])->toBe(30);
});

it('answers the traffic card with nothing, not an error, when the agent has no statistics', function () {
    $this->getJson('/apps/shop/traffic')->assertOk()->assertJson(['releases' => []]);
});

it('keeps the traffic card to people who may see the app', function () {
    $nobody = User::factory()->regular()->create();

    $this->actingAs($nobody)->getJson('/apps/shop/traffic')->assertForbidden();
});

it('treats a rolled back release as finished, and in what the API reports as not succeeded', function () {
    $second = ($this->deploy)();
    $second->forceFill(['status' => DeploymentStatus::RolledBack, 'error' => 'worse'])->save();

    expect($second->status->isFinished())->toBeTrue();
    $user = User::factory()->create();
    $token = app(TokenAccess::class)->create($user, 'ci', [TokenAccess::READ], [], 30)->plainTextToken;
    app('auth')->forgetGuards();

    $this->withToken($token)->getJson("/api/v1/apps/shop/deployments/{$second->number}")->assertOk()
        ->assertJsonPath('data.status', 'rolled_back')->assertJsonPath('data.finished', true)->assertJsonPath('data.succeeded', false);
});

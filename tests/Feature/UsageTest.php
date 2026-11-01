<?php

use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\ResourceUsage;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop', 'memory_limit_mb' => 256]);
    $live = Deployment::factory()->create([
        'app_id' => $this->shop->id, 'number' => 4, 'status' => DeploymentStatus::Active,
        'container_name' => 'shop-4', 'host_port' => 20001,
    ]);
    $this->shop->update(['active_deployment_id' => $live->id]);
    $this->host->runContainer(new ContainerSpec('shop-4', 'commypanel/shop:4', 20001, 8080, memoryLimitMb: 256));
});

describe('server usage', function () {
    it('returns the host and its containers with the app they belong to', function () {
        $json = $this->getJson('/server/usage')->assertOk()->json();

        expect($json['interval_ms'])->toBe(2000)
            ->and($json['host'])->toHaveKeys(['ready', 'cpu_percent', 'cpus', 'mem_total', 'mem_used', 'disk_total', 'disk_used', 'net_rx_bps', 'net_tx_bps'])
            ->and($json['host']['cpus'])->toBe(4)
            ->and($json['host_history'])->toBe([])
            ->and($json['containers'])->toHaveCount(1)
            ->and($json['containers'][0])->toMatchArray(['name' => 'shop-4', 'app' => ['slug' => 'shop', 'name' => $this->shop->name], 'release' => 4, 'mem_limit' => 256 * 1024 ** 2])
            ->and($json['containers'][0]['history'])->toBe([]);
    });

    it('adds the last few minutes only when asked', function () {
        $json = $this->getJson('/server/usage?history=1')->assertOk()->json();

        expect($json['host_history'])->toHaveCount(150)
            ->and($json['host_history'][0])->toHaveKeys(['t', 'cpu', 'mem', 'rx', 'tx'])
            ->and($json['host_history'][149]['t'])->toBeGreaterThan($json['host_history'][0]['t'])
            ->and($json['containers'][0]['history'])->toHaveCount(150);
    });

    it('does not list stopped containers', function () {
        $this->host->stopContainer('shop-4');

        expect($this->getJson('/server/usage')->json('containers'))->toBe([]);
    });

    it('says so when the agent cannot be reached', function () {
        app()->instance(HostDriver::class, new class extends FakeHostDriver
        {
            public function resourceUsage(bool $history = false): ResourceUsage
            {
                throw new HostOperationFailed('The CommyPanel agent is not reachable.');
            }
        });

        $this->getJson('/server/usage')->assertStatus(502)->assertJson(['error' => 'The CommyPanel agent is not reachable.']);
    });
});

describe('app usage', function () {
    it('returns the live container of the app, and nothing of other apps', function () {
        $other = App::factory()->create(['slug' => 'blog']);
        $otherLive = Deployment::factory()->create(['app_id' => $other->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'container_name' => 'blog-1']);
        $other->update(['active_deployment_id' => $otherLive->id]);
        $this->host->runContainer(new ContainerSpec('blog-1', 'commypanel/blog:1', 20002, 8080));

        $json = $this->getJson('/apps/shop/usage?history=1')->assertOk()->json();

        expect($json['live'])->toBeTrue()
            ->and($json['memory_limit_mb'])->toBe(256)
            ->and($json['container']['name'])->toBe('shop-4')
            ->and($json['container']['history'])->toHaveCount(150)
            ->and(json_encode($json))->not->toContain('blog-1');
    });

    it('has no container while the app is stopped', function () {
        $this->host->stopContainer('shop-4');

        $this->getJson('/apps/shop/usage')->assertOk()->assertJson(['live' => true, 'container' => null]);
    });

    it('is not live without a live release', function () {
        App::factory()->create(['slug' => 'idle']);

        $this->getJson('/apps/idle/usage')->assertOk()->assertJson(['live' => false, 'container' => null]);
    });

    it('reports an unreachable agent', function () {
        app()->instance(HostDriver::class, new class extends FakeHostDriver
        {
            public function resourceUsage(bool $history = false): ResourceUsage
            {
                throw new HostOperationFailed('The CommyPanel agent is not reachable.');
            }
        });

        $this->getJson('/apps/shop/usage')->assertStatus(502);
    });
});

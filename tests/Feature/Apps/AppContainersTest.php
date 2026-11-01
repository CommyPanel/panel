<?php

use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\Volume;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\DockerUnavailable;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\Deployment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['commypanel.data_path' => '/var/lib/commypanel']);
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $this->blog = App::factory()->create(['slug' => 'blog']);

    $old = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Superseded, 'container_name' => 'shop-1']);
    $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Active, 'container_name' => 'shop-2']);
    $this->shop->update(['active_deployment_id' => $live->id]);
    Deployment::factory()->create(['app_id' => $this->blog->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'container_name' => 'blog-1']);

    $this->host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));
    $this->host->runContainer(new ContainerSpec('shop-2', 'commypanel/shop:2', 20002, 8080, volumes: [new Volume('/var/lib/commypanel/volumes/shop/uploads', '/app/uploads')]));
    $this->host->runContainer(new ContainerSpec('blog-1', 'commypanel/blog:1', 20003, 3000));
});

it('shows the app\'s containers with release, networks, published ports and volumes', function () {
    $json = $this->getJson('/apps/shop/containers')->assertOk()->json();

    expect(collect($json['containers'])->pluck('name')->all())->toBe(['shop-1', 'shop-2'])
        ->and($json['containers'][1])->toMatchArray(['release' => 2, 'live' => true, 'state' => 'running', 'image' => 'commypanel/shop:2'])
        ->and($json['containers'][1]['ports'][0])->toMatchArray(['container' => 8080, 'host' => 20002, 'host_ip' => '127.0.0.1'])
        ->and($json['containers'][1]['mounts'][0])->toMatchArray(['source' => 'shop/uploads', 'destination' => '/app/uploads', 'read_only' => false])
        ->and($json['containers'][1]['networks'][0]['name'])->toBe('commypanel')
        ->and($json['containers'][0]['live'])->toBeFalse();
});

it('does not list another app\'s containers as this app\'s', function () {
    $json = $this->getJson('/apps/shop/containers')->json();

    expect(collect($json['containers'])->pluck('name')->all())->not->toContain('blog-1');
    expect($this->getJson('/apps/blog/containers')->json('containers.0.name'))->toBe('blog-1');
});

it('says who else is on the same network, including other apps', function () {
    $networks = $this->getJson('/apps/shop/containers')->json('networks');

    expect($networks)->toHaveCount(1)
        ->and($networks[0])->toMatchArray(['name' => 'commypanel', 'subnet' => '172.20.0.0/16', 'internal' => false]);

    $members = collect($networks[0]['containers'])->keyBy('name');

    expect($members['shop-2'])->toMatchArray(['this_app' => true, 'app' => ['slug' => 'shop', 'name' => $this->shop->name]])
        ->and($members['blog-1'])->toMatchArray(['this_app' => false, 'app' => ['slug' => 'blog', 'name' => $this->blog->name]]);
});

it('has nothing to show for an app without containers', function () {
    $empty = App::factory()->create(['slug' => 'empty']);

    $this->getJson('/apps/empty/containers')->assertOk()->assertJson(['containers' => [], 'networks' => []]);
});

it('reports Docker being down and agent trouble separately', function () {
    $down = new class extends FakeHostDriver
    {
        public function listContainers(): array
        {
            throw new DockerUnavailable('Agent error: docker is not available');
        }
    };
    app()->instance(HostDriver::class, $down);

    $this->getJson('/apps/shop/containers')->assertStatus(503)->assertJson(['docker_down' => true]);
});

it('gives the server\'s Docker page the same detail and the networks', function () {
    $this->get('/server/docker')->assertInertia(fn (Assert $page) => $page
        ->where('networks.0.name', 'commypanel')
        ->has('networks.0.containers', 3)
        ->where('containers.1.name', 'shop-2')
        ->where('containers.1.ports.0.host', 20002)
        ->where('containers.1.mounts.0.source', 'shop/uploads')
        ->where('containers.1.networks.0.name', 'commypanel')
        ->where('containers.1.app.slug', 'shop'));
});

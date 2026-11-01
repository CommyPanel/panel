<?php

use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop']);
});

function goLive(App $app): void
{
    $live = Deployment::factory()->create([
        'app_id' => $app->id, 'number' => 4, 'status' => DeploymentStatus::Active,
        'container_name' => 'shop-4', 'host_port' => 20001,
    ]);
    $app->update(['active_deployment_id' => $live->id]);
    app(HostDriver::class)->runContainer(new ContainerSpec('shop-4', 'commypanel/shop:4', 20001, 8080));
}

it('reads the running container\'s logs', function () {
    goLive($this->shop);

    $this->getJson('/apps/shop/logs')->assertOk()
        ->assertJson(['live' => true, 'lines' => 500])
        ->assertJsonPath('logs', "[fake] logs for shop-4\n");

    expect(collect($this->host->actions())->firstWhere('action', 'containerLogs')['args'])
        ->toMatchArray(['name' => 'shop-4', 'tail' => 500]);
});

it('keeps the number of lines within bounds', function (int $asked, int $used) {
    goLive($this->shop);

    $this->getJson("/apps/shop/logs?tail={$asked}")->assertOk()->assertJson(['lines' => $used]);
})->with([
    'too few' => [1, 10],
    'in range' => [1234, 1234],
    'too many' => [999999, 5000],
]);

it('has nothing to show when the app is not live', function () {
    $this->getJson('/apps/shop/logs')->assertOk()->assertJson(['live' => false, 'logs' => '']);
    $this->get('/apps/shop/logs/download')->assertNotFound();
});

it('answers 502 when the agent cannot be reached', function () {
    goLive($this->shop);
    // A failure applies to the next call only.
    $this->host->failOn('containerLogs');
    $this->getJson('/apps/shop/logs')->assertStatus(502)->assertJsonStructure(['error']);

    $this->host->failOn('containerLogs');
    $this->get('/apps/shop/logs/download')->assertStatus(502);
});

it('downloads the logs as a text file and records it in the audit log', function () {
    goLive($this->shop);

    $response = $this->get('/apps/shop/logs/download')->assertOk();

    expect($response->headers->get('Content-Disposition'))->toMatch('/^attachment; filename="shop-\d{4}-\d{2}-\d{2}-\d{6}\.log"$/')
        ->and($response->headers->get('Content-Type'))->toStartWith('text/plain')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->getContent())->toBe("[fake] logs for shop-4\n")
        ->and(collect($this->host->actions())->firstWhere('action', 'containerLogs')['args']['tail'])->toBe(5000)
        ->and(AuditLog::query()->where('action', 'app.logs_downloaded')->count())->toBe(1);
});

it('does not show another app\'s logs through this app\'s address', function () {
    goLive($this->shop);
    $other = App::factory()->create(['slug' => 'blog']);

    $this->getJson('/apps/blog/logs')->assertOk()->assertJson(['live' => false]);
    expect($this->host->actionNames())->not->toContain('containerLogs');
});

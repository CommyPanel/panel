<?php

use App\Enums\DeploymentStatus;
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
    $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 4, 'status' => DeploymentStatus::Active, 'container_name' => 'shop-4']);
    $this->shop->update(['active_deployment_id' => $live->id]);

    $this->open = fn (string $base = '') => $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson($base.'/apps/shop/terminal');
});

it('hands out a ticket for the live container and records it', function () {
    $response = ($this->open)()->assertOk();

    expect($response->json())->toMatchArray(['path' => '/terminal', 'container' => 'shop-4', 'expires_in' => 30])
        ->and($response->json('ticket'))->toStartWith('fake-ticket-')
        ->and(AuditLog::query()->where('action', 'app.terminal_opened')->first()->metadata)->toMatchArray(['app' => 'shop', 'container' => 'shop-4']);
});

it('binds the ticket to the origin the page is served from', function () {
    ($this->open)('https://panel.example.com')->assertOk();

    expect(collect($this->host->actions())->firstWhere('action', 'createTerminalTicket')['args'])
        ->toBe(['container' => 'shop-4', 'origin' => 'https://panel.example.com']);
});

it('includes a non-standard port in the origin', function () {
    ($this->open)('https://203.0.113.7:8443')->assertOk();

    expect(collect($this->host->actions())->firstWhere('action', 'createTerminalTicket')['args']['origin'])->toBe('https://203.0.113.7:8443');
});

it('cannot name a container: only the app\'s live one is used', function () {
    $this->withSession(['auth.password_confirmed_at' => time()])
        ->postJson('/apps/shop/terminal', ['container' => 'blog-1', 'name' => 'blog-1'])->assertOk()->assertJson(['container' => 'shop-4']);
});

it('asks for the password first and writes nothing until it has it', function () {
    $this->postJson('/apps/shop/terminal')->assertStatus(423);

    expect($this->host->actionNames())->not->toContain('createTerminalTicket')
        ->and(AuditLog::query()->where('action', 'app.terminal_opened')->count())->toBe(0);
});

it('refuses when the app has no running container', function (string $case) {
    if ($case === 'stopped') {
        $this->shop->update(['stopped_at' => now()]);
    } else {
        $this->shop->update(['active_deployment_id' => null]);
    }

    ($this->open)()->assertStatus(422)->assertJsonStructure(['error']);

    expect($this->host->actionNames())->not->toContain('createTerminalTicket');
})->with(['stopped' => 'stopped', 'never deployed' => 'none']);

it('reports an agent that cannot be reached, after recording the attempt', function () {
    $this->host->failOn('createTerminalTicket');

    ($this->open)()->assertStatus(502)->assertJsonStructure(['error']);

    expect(AuditLog::query()->where('action', 'app.terminal_opened')->count())->toBe(1);
});

it('limits how fast tickets can be asked for', function () {
    foreach (range(1, 10) as $_) {
        ($this->open)()->assertOk();
    }

    ($this->open)()->assertStatus(429);
});

it('does not work for another app\'s address', function () {
    App::factory()->create(['slug' => 'blog']);

    $this->withSession(['auth.password_confirmed_at' => time()])->postJson('/apps/blog/terminal')->assertStatus(422);
});

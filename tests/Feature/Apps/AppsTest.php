<?php

use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Enums\Stack;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

function validAppInput(array $overrides = []): array
{
    return [
        'name' => 'My Shop',
        'git_url' => 'https://github.com/acme/shop.git',
        'git_branch' => 'main',
        'container_port' => 8080,
        'health_path' => '/health',
        ...$overrides,
    ];
}

function wizardInput(array $overrides = []): array
{
    return ['name' => 'My Shop', 'source' => 'git', 'git_url' => 'https://github.com/acme/shop.git', 'git_branch' => 'main', ...$overrides];
}

beforeEach(fn () => $this->actingAs(User::factory()->create()));

it('requires login for every app route', function () {
    auth()->logout();
    $app = App::factory()->create();

    $this->get('/apps')->assertRedirect('/login');
    $this->post('/apps', wizardInput())->assertRedirect('/login');
    $this->get("/apps/{$app->slug}")->assertRedirect('/login');
    $this->delete("/apps/{$app->slug}")->assertRedirect('/login');
});

it('lists apps with their status', function () {
    $running = App::factory()->create(['name' => 'Alpha']);
    $active = Deployment::factory()->create(['app_id' => $running->id, 'number' => 1, 'status' => DeploymentStatus::Active]);
    $running->update(['active_deployment_id' => $active->id]);

    $failed = App::factory()->create(['name' => 'Beta']);
    Deployment::factory()->create(['app_id' => $failed->id, 'number' => 1, 'status' => DeploymentStatus::Failed]);

    $deploying = App::factory()->create(['name' => 'Gamma']);
    Deployment::factory()->create(['app_id' => $deploying->id, 'number' => 1, 'status' => DeploymentStatus::Building]);

    App::factory()->create(['name' => 'Delta']);

    $this->get('/apps')->assertInertia(fn (Assert $page) => $page
        ->component('apps/index')
        ->where('apps.0.status', 'running')->where('apps.0.active_release', 1)
        ->where('apps.1.status', 'failed')
        ->where('apps.2.status', 'new')
        ->where('apps.3.status', 'deploying'));
});

it('starts a draft app at step 1 with a generated slug and records it', function () {
    $this->post('/apps', wizardInput())->assertRedirect('/apps/my-shop/setup');

    $app = App::query()->firstOrFail();
    expect($app->slug)->toBe('my-shop')
        ->and($app->git_branch)->toBe('main')
        ->and($app->setup_step)->toBe(SetupStep::Source)
        ->and($app->status())->toBe(AppStatus::Setup)
        ->and(AuditLog::query()->where('action', 'app.setup_started')->exists())->toBeTrue();

    $this->post('/apps', wizardInput())->assertRedirect('/apps/my-shop-2/setup');
});

it('starts an upload app without a Git address', function () {
    $this->post('/apps', ['name' => 'Zipped', 'source' => 'upload'])->assertRedirect('/apps/zipped/setup');

    expect(App::query()->firstOrFail())->source_type->toBe(SourceType::Upload)->git_url->toBeNull();
});

it('keeps the chosen app type as a hint', function () {
    $this->post('/apps', wizardInput(['stack_hint' => 'go']));

    expect(App::query()->firstOrFail()->stack_hint)->toBe(Stack::Go);
});

it('validates step 1', function (array $overrides, string $field) {
    $this->post('/apps', wizardInput($overrides))->assertSessionHasErrors($field);

    expect(App::query()->count())->toBe(0);
})->with([
    'local git path' => [['git_url' => '/etc'], 'git_url'],
    'option injection' => [['git_url' => '--upload-pack=x'], 'git_url'],
    'missing git url' => [['git_url' => ''], 'git_url'],
    'bad branch' => [['git_branch' => '-x'], 'git_branch'],
    'unknown type' => [['stack_hint' => 'cobol'], 'stack_hint'],
    'unknown source' => [['source' => 'ftp'], 'source'],
    'missing name' => [['name' => ''], 'name'],
]);

it('saves the release command, and clears it when left empty', function () {
    $app = App::factory()->create();

    $this->patch("/apps/{$app->slug}", validAppInput(['release_command' => "sh -c 'npm run migrate && npm run seed'"]))->assertSessionHasNoErrors();
    expect($app->fresh()->release_command)->toBe("sh -c 'npm run migrate && npm run seed'");

    $this->patch("/apps/{$app->slug}", validAppInput(['release_command' => '']))->assertSessionHasNoErrors();
    expect($app->fresh()->release_command)->toBeNull();
});

it('sends a draft app to its setup instead of its page', function () {
    $app = App::factory()->create(['setup_step' => SetupStep::Source]);

    $this->get("/apps/{$app->slug}")->assertRedirect("/apps/{$app->slug}/setup");
});

it('validates settings on update', function (array $overrides, string $field) {
    $app = App::factory()->create();

    $this->patch("/apps/{$app->slug}", validAppInput($overrides))->assertSessionHasErrors($field);
})->with([
    'local git path' => [['git_url' => '/etc'], 'git_url'],
    'bad branch' => [['git_branch' => '-x'], 'git_branch'],
    'port too big' => [['container_port' => 70000], 'container_port'],
    'health path without slash' => [['health_path' => 'health'], 'health_path'],
    'health path with spaces' => [['health_path' => '/a b'], 'health_path'],
    'tiny memory' => [['memory_limit_mb' => 1], 'memory_limit_mb'],
    'missing name' => [['name' => ''], 'name'],    'release command with a shell operator' => [['release_command' => 'npm run migrate && npm run seed'], 'release_command'],
    'release command with an open quote' => [['release_command' => "sh -c 'x"], 'release_command'],
]);

it('shows an app without leaking secrets', function () {
    $app = App::factory()->create(['deploy_key_private' => 'PRIVATE-KEY', 'deploy_key_public' => 'ssh-ed25519 AAAA test']);
    AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'DB_PASSWORD', 'value' => 'hunter2']);

    $response = $this->get("/apps/{$app->slug}");

    $response->assertInertia(fn (Assert $page) => $page
        ->component('apps/show')
        ->where('app.deploy_key_public', 'ssh-ed25519 AAAA test')
        ->where('env_keys', ['DB_PASSWORD']));

    expect($response->getContent())->not->toContain('PRIVATE-KEY')->not->toContain('hunter2');
});

it('updates settings', function () {
    $app = App::factory()->create();

    $this->patch("/apps/{$app->slug}", validAppInput(['name' => 'Renamed', 'container_port' => 3000, 'memory_limit_mb' => 256]))
        ->assertRedirect();

    expect($app->fresh())->name->toBe('Renamed')
        ->container_port->toBe(3000)
        ->memory_limit_mb->toBe(256)
        ->slug->toBe($app->slug);
});

it('deletes an app only after the name is typed, cleaning up the host', function () {
    $host = app(HostDriver::class);
    $app = App::factory()->create(['slug' => 'shop']);
    Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'container_name' => 'shop-1', 'image_tag' => 'commypanel/shop:1']);
    Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'container_name' => 'shop-2', 'image_tag' => 'commypanel/shop:2', 'image_available' => false]);

    $this->delete('/apps/shop', ['confirm' => 'wrong'])->assertSessionHasErrors('confirm');
    expect(App::query()->count())->toBe(1);

    $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

    expect(App::query()->count())->toBe(0)
        ->and(Deployment::query()->count())->toBe(0)
        ->and(collect($host->actions())->where('action', 'removeContainer')->pluck('args.name')->all())->toBe(['shop-1', 'shop-2'])
        ->and(collect($host->actions())->where('action', 'removeImage')->pluck('args.tag')->all())->toBe(['commypanel/shop:1'])
        ->and(AuditLog::query()->where('action', 'app.deleted')->exists())->toBeTrue();
});

it('still deletes the app but warns when host cleanup fails', function () {
    $host = new class extends FakeHostDriver
    {
        public function removeContainer(string $name): void
        {
            throw new HostOperationFailed('Docker is not available');
        }
    };
    app()->instance(HostDriver::class, $host);

    $app = App::factory()->create(['slug' => 'shop']);
    Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'container_name' => 'shop-1']);

    $this->delete('/apps/shop', ['confirm' => 'shop'])
        ->assertRedirect('/apps')
        ->assertSessionHas('warning');

    expect(App::query()->count())->toBe(0);
});

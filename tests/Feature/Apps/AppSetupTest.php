<?php

use App\Deploy\Source\UploadStorage;
use App\Enums\SetupStep;
use App\Enums\SourceType;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Archives;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['commypanel.data_path' => storage_path('framework/testing/setup-'.bin2hex(random_bytes(4)))]);
});

afterEach(fn () => File::deleteDirectory((string) config('commypanel.data_path')));

function draft(array $attributes = []): App
{
    return App::factory()->create(['setup_step' => SetupStep::Source, 'stack' => null, ...$attributes]);
}

it('shows the setup page for a draft only', function () {
    $app = draft();

    $this->get("/apps/{$app->slug}/setup")->assertInertia(fn (Assert $page) => $page
        ->component('apps/setup')
        ->where('app.setup_step', 'source')
        ->where('app.source_verified', false)
        ->where('provider.name', 'your Git server'));

    $app->update(['setup_step' => null]);
    $this->get("/apps/{$app->slug}/setup")->assertRedirect("/apps/{$app->slug}");
});

it('verifies a public repository and records it', function () {
    Process::fake(['*' => Process::result("abc\trefs/heads/main\n")]);
    $app = draft(['git_url' => 'https://github.com/acme/shop.git']);

    $this->postJson("/apps/{$app->slug}/setup/check")
        ->assertOk()->assertJson(['state' => 'public', 'ready' => true]);

    expect($app->fresh()->source_verified_at)->not->toBeNull();
});

it('asks for a deploy key when the repository is private, and does not verify', function () {
    Process::fake(['*' => Process::result('', "fatal: could not read Username for 'https://github.com'", 128)]);
    $app = draft(['git_url' => 'https://github.com/acme/private.git']);

    $this->postJson("/apps/{$app->slug}/setup/check")
        ->assertOk()->assertJson(['state' => 'key_required', 'ready' => false]);

    expect($app->fresh()->source_verified_at)->toBeNull();
});

it('switches to the SSH address once the deploy key works', function () {
    Process::fake(['*' => Process::result("abc\trefs/heads/main\n")]);
    $app = draft(['git_url' => 'https://github.com/acme/private.git', 'deploy_key_private' => 'KEY', 'deploy_key_public' => 'ssh-ed25519 AAAA']);

    $this->postJson("/apps/{$app->slug}/setup/check")->assertJson(['state' => 'key_works']);

    expect($app->fresh())->git_url->toBe('git@github.com:acme/private.git')->source_verified_at->not->toBeNull();
});

it('forgets the verification when the branch changes', function () {
    Process::fake(['*' => Process::result("abc\trefs/heads/main\n")]);
    $app = draft(['source_verified_at' => now()]);

    $this->postJson("/apps/{$app->slug}/setup/check", ['git_branch' => 'nope'])
        ->assertJson(['state' => 'branch_missing', 'ready' => false]);

    expect($app->fresh())->git_branch->toBe('nope')->source_verified_at->toBeNull();
});

it('does not run a check for a finished app or an upload app', function () {
    $this->postJson('/apps/'.App::factory()->create()->slug.'/setup/check')->assertNotFound();
    $this->postJson('/apps/'.draft(['source_type' => SourceType::Upload, 'git_url' => null])->slug.'/setup/check')->assertNotFound();
});

it('stores an uploaded archive and marks the source ready', function () {
    $app = draft(['source_type' => SourceType::Upload, 'git_url' => null]);
    $path = Archives::zip(storage_path('framework/testing/up-'.bin2hex(random_bytes(3)).'.zip'), ['proj/go.mod' => 'module x', 'proj/main.go' => 'package main']);
    $file = new UploadedFile($path, 'proj.zip', 'application/zip', null, true);

    $this->post("/apps/{$app->slug}/setup/upload", ['archive' => $file])->assertSessionHasNoErrors();

    $storage = app(UploadStorage::class);
    expect($storage->has($app))->toBeTrue()
        ->and(file_get_contents($storage->path($app).'/go.mod'))->toBe('module x')
        ->and($app->fresh()->source_verified_at)->not->toBeNull();

    File::delete($path);
});

it('rejects a hostile archive without keeping anything', function () {
    $app = draft(['source_type' => SourceType::Upload, 'git_url' => null]);
    $path = storage_path('framework/testing/evil-'.bin2hex(random_bytes(3)).'.tgz');
    file_put_contents($path, Archives::tarGz([['name' => '../evil', 'content' => 'x']]));

    $this->post("/apps/{$app->slug}/setup/upload", ['archive' => new UploadedFile($path, 'evil.tgz', null, null, true)])
        ->assertSessionHasErrors('archive');

    expect(app(UploadStorage::class)->has($app))->toBeFalse()
        ->and($app->fresh()->source_verified_at)->toBeNull();

    File::delete($path);
});

it('replaces an earlier upload', function () {
    $app = draft(['source_type' => SourceType::Upload, 'git_url' => null]);
    $upload = function (array $files) use ($app) {
        $path = Archives::zip(storage_path('framework/testing/r-'.bin2hex(random_bytes(3)).'.zip'), $files);
        $this->post("/apps/{$app->slug}/setup/upload", ['archive' => new UploadedFile($path, 'a.zip', null, null, true)]);
        File::delete($path);
    };

    $upload(['old.txt' => '1']);
    $upload(['new.txt' => '2']);

    $dir = app(UploadStorage::class)->path($app);
    expect(file_exists("{$dir}/new.txt"))->toBeTrue()->and(file_exists("{$dir}/old.txt"))->toBeFalse();
});

it('only moves on once the source is verified', function () {
    $app = draft();
    $this->post("/apps/{$app->slug}/setup/continue")->assertSessionHasErrors('source');
    expect($app->fresh()->setup_step)->toBe(SetupStep::Source);

    $app->update(['source_verified_at' => now()]);
    $this->post("/apps/{$app->slug}/setup/continue")->assertRedirect("/apps/{$app->slug}/setup");
    expect($app->fresh()->setup_step)->toBe(SetupStep::Settings);

    $this->post("/apps/{$app->slug}/setup/back")->assertRedirect();
    expect($app->fresh()->setup_step)->toBe(SetupStep::Source);
});

it('saves settings and variables and moves on to the domain step', function () {
    Queue::fake();
    $app = draft(['setup_step' => SetupStep::Settings, 'source_verified_at' => now()]);

    $this->post("/apps/{$app->slug}/setup/settings", [
        'container_port' => 3000,
        'health_path' => '/healthz',
        'memory_limit_mb' => 256,
        'cpu_limit' => 0.5,
        'health_timeout' => 180,
        'env' => "# comment\nGREETING=\"hello there\"\nexport TOKEN=abc # trailing\nPORT=1\nnot valid\n",
    ])->assertRedirect("/apps/{$app->slug}/setup");

    $app->refresh();
    expect($app)->setup_step->toBe(SetupStep::Domain)->container_port->toBe(3000)->memory_limit_mb->toBe(256)->health_timeout->toBe(180)
        ->and(AppEnvVar::query()->where('app_id', $app->id)->pluck('value', 'key')->all())->toBe(['GREETING' => 'hello there', 'TOKEN' => 'abc'])
        ->and(Deployment::query()->count())->toBe(0);

    Queue::assertNothingPushed();
});

it('finishes setup with the first deployment, with or without a domain', function (bool $withDomain) {
    Queue::fake();
    $app = draft(['setup_step' => SetupStep::Domain, 'source_verified_at' => now()]);

    if ($withDomain) {
        $this->post("/apps/{$app->slug}/domains", ['name' => 'shop.example.com', 'redirect' => 'none'])->assertSessionHasNoErrors();
    }

    $this->post("/apps/{$app->slug}/setup/finish")->assertRedirect("/apps/{$app->slug}/deployments/1");

    expect($app->fresh()->setup_step)->toBeNull()
        ->and(Deployment::query()->count())->toBe(1);

    Queue::assertPushed(RunDeployment::class);
})->with([true, false]);

it('lets the user go back a step', function () {
    $app = draft(['setup_step' => SetupStep::Domain, 'source_verified_at' => now()]);

    $this->post("/apps/{$app->slug}/setup/back");
    expect($app->fresh()->setup_step)->toBe(SetupStep::Settings);

    $this->post("/apps/{$app->slug}/setup/back");
    expect($app->fresh()->setup_step)->toBe(SetupStep::Source);

    $this->post("/apps/{$app->slug}/setup/back")->assertNotFound();
});

it('does not take settings out of order or with a bad port', function () {
    Queue::fake();
    $app = draft();

    $this->post("/apps/{$app->slug}/setup/settings", ['container_port' => 80, 'health_path' => '/'])->assertNotFound();
    $this->post("/apps/{$app->slug}/setup/finish")->assertNotFound();

    $app->update(['setup_step' => SetupStep::Settings, 'source_verified_at' => now()]);
    $this->post("/apps/{$app->slug}/setup/settings", ['container_port' => 99999, 'health_path' => '/'])->assertSessionHasErrors('container_port');

    Queue::assertNothingPushed();
});

it('cancels a draft and removes its upload', function () {
    $app = draft(['source_type' => SourceType::Upload, 'git_url' => null]);
    File::ensureDirectoryExists(app(UploadStorage::class)->path($app));
    file_put_contents(app(UploadStorage::class)->path($app).'/a.txt', 'x');

    $this->delete("/apps/{$app->slug}/setup")->assertRedirect('/apps');

    expect(App::query()->count())->toBe(0)->and(is_dir(app(UploadStorage::class)->path($app)))->toBeFalse();
});

it('will not cancel an app that is already set up', function () {
    $this->delete('/apps/'.App::factory()->create()->slug.'/setup')->assertNotFound();
});

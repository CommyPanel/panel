<?php

use App\Enums\SetupStep;
use App\Enums\Stack;
use App\Jobs\InspectSourceJob;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * The extra steps of a Laravel app: settings, database, services, volumes, domain, and a last look
 * at the variables and the release script before the first deployment.
 */
beforeEach(function () {
    $this->actingAs(User::factory()->create());
    config(['commypanel.data_path' => storage_path('framework/testing/lwiz-'.bin2hex(random_bytes(4)))]);
    Queue::fake();

    $this->detected = [
        'stack' => 'laravel', 'laravel_major' => 12, 'php' => '8.4', 'health_path' => '/up', 'node_manager' => 'pnpm',
        'services' => [
            ['kind' => 'scheduler', 'name' => 'scheduler', 'detected_by' => 'Laravel', 'default_command' => 'php artisan schedule:work', 'supported' => true, 'default_enabled' => true, 'overlap' => 'stop_first'],
            ['kind' => 'horizon', 'name' => 'horizon', 'detected_by' => 'laravel/horizon', 'default_command' => 'php artisan horizon', 'supported' => true, 'default_enabled' => false, 'overlap' => 'stop_first'],
            ['kind' => 'queue', 'name' => 'queue', 'detected_by' => 'Laravel', 'default_command' => 'php artisan queue:work --tries=3 --timeout=90', 'supported' => true, 'default_enabled' => false, 'overlap' => 'overlap'],
        ],
        'suggested_volumes' => [['path' => '/app/storage', 'why' => 'uploads, logs and caches']],
    ];
    $this->shop = App::factory()->create([
        'slug' => 'shop', 'setup_step' => SetupStep::Settings, 'stack' => null, 'stack_hint' => Stack::Laravel,
        'source_verified_at' => now(), 'detected' => $this->detected, 'container_port' => 8080, 'health_path' => '/up',
    ]);
    $this->settings = fn (array $extra = []) => [
        'container_port' => 8080, 'health_path' => '/up', 'deploy_strategy' => 'rolling', 'env' => '',
        'stack_options' => ['php' => '8.4', 'node_manager' => 'pnpm'], ...$extra,
    ];
});

afterEach(fn () => File::deleteDirectory((string) config('commypanel.data_path')));

it('looks at the code when the source is verified, and shows the answer on the settings page', function () {
    $this->shop->update(['setup_step' => SetupStep::Source, 'detected' => null]);

    $this->post('/apps/shop/setup/continue')->assertRedirect('/apps/shop/setup');
    Queue::assertPushed(InspectSourceJob::class);

    $this->shop->update(['detected' => $this->detected]);
    $this->get('/apps/shop/setup')->assertInertia(fn (Assert $page) => $page
        ->where('app.setup_step', 'settings')
        ->where('laravel.inspecting', false)
        ->where('laravel.detected.php', '8.4'));
});

it('goes from the settings to the database, services, volumes, domain and review in order', function () {
    $this->post('/apps/shop/setup/settings', ($this->settings)())->assertRedirect();
    expect($this->shop->fresh()->setup_step)->toBe(SetupStep::Database)
        ->and($this->shop->fresh()->stack_options)->toMatchArray(['php' => '8.4', 'node_manager' => 'pnpm']);

    // No database chosen yet: the step cannot be left.
    $this->post('/apps/shop/setup/database/continue')->assertSessionHasErrors('setup');
    $this->putJson('/apps/shop/setup/database', ['mode' => 'none'])->assertOk();
    $this->post('/apps/shop/setup/database/continue')->assertRedirect();
    expect($this->shop->fresh()->setup_step)->toBe(SetupStep::Services);

    // The scheduler is on, what the code showed is off.
    $processes = $this->shop->processes()->orderBy('sort')->get();
    expect($processes->pluck('name')->all())->toBe(['scheduler', 'horizon', 'queue'])
        ->and($processes->pluck('enabled')->all())->toBe([true, false, false]);

    $this->post('/apps/shop/setup/services/continue')->assertRedirect();
    expect($this->shop->fresh()->setup_step)->toBe(SetupStep::Volumes)
        ->and($this->shop->volumes()->pluck('path')->all())->toBe(['/app/storage']);

    $this->post('/apps/shop/setup/volumes/continue')->assertRedirect();
    expect($this->shop->fresh()->setup_step)->toBe(SetupStep::Domain);

    $this->post('/apps/shop/setup/domain/continue')->assertRedirect();
    $shop = $this->shop->fresh();

    expect($shop->setup_step)->toBe(SetupStep::Review)
        ->and($shop->release_script)->toBe("php artisan migrate --force\n")
        ->and($shop->nginx_custom)->toContain('client_max_body_size')
        ->and($shop->env_text)->toContain('APP_KEY=base64:')->toContain('APP_ENV=production')
        ->and($shop->environment())->toHaveKey('APP_KEY');

    $this->get('/apps/shop/setup')->assertInertia(fn (Assert $page) => $page
        ->where('laravel.review.database.mode', 'none')
        ->where('laravel.review.volumes', ['/app/storage'])
        ->where('laravel.review.processes.0.name', 'scheduler'));
});

it('keeps a person\'s volumes and variables when they walk back and forward again', function () {
    $this->post('/apps/shop/setup/settings', ($this->settings)());
    $this->putJson('/apps/shop/setup/database', ['mode' => 'none']);
    $this->post('/apps/shop/setup/database/continue');
    $this->post('/apps/shop/setup/services/continue');
    $this->shop->volumes()->delete();

    $this->post('/apps/shop/setup/back');
    $this->post('/apps/shop/setup/services/continue');
    expect($this->shop->volumes()->count())->toBe(0);

    $this->post('/apps/shop/setup/volumes/continue');
    $this->post('/apps/shop/setup/domain/continue');
    $this->shop->forceFill(['env_text' => "APP_NAME=Mine\n"])->save();
    $this->post('/apps/shop/setup/back');
    $this->post('/apps/shop/setup/domain/continue');

    expect($this->shop->fresh()->env_text)->toBe("APP_NAME=Mine\n");
});

it('steps back one step at a time', function () {
    foreach ([
        [SetupStep::Review, SetupStep::Domain], [SetupStep::Domain, SetupStep::Volumes], [SetupStep::Volumes, SetupStep::Services],
        [SetupStep::Services, SetupStep::Database], [SetupStep::Database, SetupStep::Settings],
    ] as [$from, $to]) {
        $this->shop->update(['setup_step' => $from]);
        $this->post('/apps/shop/setup/back');
        expect($this->shop->fresh()->setup_step)->toBe($to);
    }
});

it('refuses the Laravel steps for an app that is not a Laravel app', function () {
    $other = App::factory()->create(['setup_step' => SetupStep::Database, 'stack' => null, 'stack_hint' => null]);

    $this->post("/apps/{$other->slug}/setup/database/continue")->assertNotFound();
    $this->post("/apps/{$other->slug}/setup/domain/continue")->assertNotFound();
});

it('does not ask for the Laravel steps of an app of another stack', function () {
    $node = App::factory()->create(['setup_step' => SetupStep::Settings, 'stack' => null, 'stack_hint' => Stack::Node, 'source_verified_at' => now()]);

    $this->post("/apps/{$node->slug}/setup/settings", ($this->settings)())->assertRedirect();

    expect($node->fresh()->setup_step)->toBe(SetupStep::Domain)->and($node->fresh()->stack_options)->toBeNull();
});

it('starts the first deployment from the review, with the release script the person wrote', function () {
    $this->shop->update(['setup_step' => SetupStep::Review, 'release_script' => "php artisan migrate --force\n"]);

    $this->post('/apps/shop/setup/finish', ['release_script' => "php artisan migrate --force\nphp artisan db:seed --force\n"])->assertRedirect();

    $shop = $this->shop->fresh();

    expect($shop->setup_step)->toBeNull()
        ->and($shop->release_script)->toBe("php artisan migrate --force\nphp artisan db:seed --force\n")
        ->and($shop->deployments()->first()->release_script)->toContain('db:seed');
    Queue::assertPushed(RunDeployment::class);
});

it('cannot finish from the domain step when it is a Laravel app', function () {
    $this->shop->update(['setup_step' => SetupStep::Domain]);

    $this->post('/apps/shop/setup/finish')->assertNotFound();
});

it('validates the PHP version and the asset manager', function () {
    $this->post('/apps/shop/setup/settings', ($this->settings)(['stack_options' => ['php' => '7.4']]))->assertSessionHasErrors('stack_options.php');
    $this->post('/apps/shop/setup/settings', ($this->settings)(['stack_options' => ['node_manager' => 'bun']]))->assertSessionHasErrors('stack_options.node_manager');
});

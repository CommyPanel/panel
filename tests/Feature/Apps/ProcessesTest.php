<?php

use App\Auth\SyncRolesAndPermissions;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\ReleaseScripts;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStatus;
use App\Enums\ProcessKind;
use App\Enums\Stack;
use App\Host\Data\TaskSpec;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppProcess;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

/**
 * The Services page of an app (its extra processes) and the release script.
 */
beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    app(SyncRolesAndPermissions::class)();
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, new FakeHealthChecker);
    app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);
    $this->host = app(HostDriver::class);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => Stack::Laravel, 'container_port' => 8080, 'health_path' => '/up']);
    $this->actingAs(User::factory()->create());

    $row = fn (string $name, string $kind, array $extra = []) => ['name' => $name, 'kind' => $kind, 'command' => null, 'enabled' => true, ...$extra];
    $this->row = $row;
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

describe('saving the list', function () {
    it('replaces the list, matching rows by name, and says changes are waiting to be applied', function () {
        AppProcess::query()->create(['app_id' => $this->shop->id, 'name' => 'queue', 'kind' => ProcessKind::Queue, 'enabled' => false]);
        AppProcess::query()->create(['app_id' => $this->shop->id, 'name' => 'old', 'kind' => ProcessKind::Custom, 'command' => 'php old.php', 'enabled' => true]);
        $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001, 'created_at' => now()->subHour()]);
        $this->shop->forceFill(['active_deployment_id' => $live->id, 'config_changed_at' => null])->save();

        $this->put('/apps/shop/processes', ['processes' => [
            ($this->row)('scheduler', 'scheduler'),
            ($this->row)('queue', 'queue', ['command' => 'php artisan queue:work --queue=mail', 'replicas' => 3, 'stop_grace_seconds' => 120, 'memory_limit_mb' => 192]),
            ($this->row)('mailer', 'custom', ['command' => 'php mailer.php --loop', 'enabled' => false]),
        ]])->assertSessionHasNoErrors();

        $rows = $this->shop->processes()->get()->keyBy('name');

        expect($rows->keys()->all())->toBe(['scheduler', 'queue', 'mailer'])
            ->and($rows['queue']->command)->toBe('php artisan queue:work --queue=mail')
            ->and($rows['queue']->replicas)->toBe(3)
            ->and($rows['queue']->enabled)->toBeTrue()
            ->and($rows['queue']->stop_grace_seconds)->toBe(120)
            ->and($rows['queue']->memory_limit_mb)->toBe(192)
            ->and($rows['scheduler']->overlap)->toBe('stop_first')
            ->and($rows['mailer']->enabled)->toBeFalse()
            ->and($this->shop->fresh()->hasPendingChanges())->toBeTrue();
    });

    it('does not store the usual command of a kind, so a newer default applies', function () {
        $this->put('/apps/shop/processes', ['processes' => [($this->row)('horizon', 'horizon', ['command' => 'php artisan horizon'])]])->assertSessionHasNoErrors();

        expect($this->shop->processes()->where('name', 'horizon')->value('command'))->toBeNull();
    });

    it('keeps the scheduler of a Laravel app: it can be switched off, not removed', function () {
        $this->put('/apps/shop/processes', ['processes' => [($this->row)('scheduler', 'scheduler')]])->assertSessionHasNoErrors();
        $this->put('/apps/shop/processes', ['processes' => []])->assertSessionHasNoErrors();

        expect($this->shop->processes()->pluck('name')->all())->toBe(['scheduler']);

        $this->put('/apps/shop/processes', ['processes' => [($this->row)('scheduler', 'scheduler', ['enabled' => false])]])->assertSessionHasNoErrors();

        expect($this->shop->processes()->value('enabled'))->toBeFalsy();
    });

    it('refuses names, commands and numbers that cannot work', function (array $row, string $field) {
        $this->put('/apps/shop/processes', ['processes' => [$row]])->assertSessionHasErrors($field);
        expect($this->shop->processes()->count())->toBe(0);
    })->with([
        'the name of the web process' => [['name' => 'web', 'kind' => 'custom', 'command' => 'php x.php', 'enabled' => true], 'processes.0.name'],
        'the name of the release task' => [['name' => 'release', 'kind' => 'custom', 'command' => 'php x.php', 'enabled' => true], 'processes.0.name'],
        'a name that ends in a number' => [['name' => 'queue-2', 'kind' => 'queue', 'enabled' => true], 'processes.0.name'],
        'capital letters' => [['name' => 'Queue', 'kind' => 'queue', 'enabled' => true], 'processes.0.name'],
        'a name that is too long for a container' => [['name' => str_repeat('a', 40), 'kind' => 'queue', 'enabled' => true], 'processes.0.name'],
        'an unknown kind' => [['name' => 'queue', 'kind' => 'cron', 'enabled' => true], 'processes.0.kind'],
        'a command with an open quote' => [['name' => 'queue', 'kind' => 'queue', 'command' => "php 'x", 'enabled' => true], 'processes.0.command'],
        'a shell operator in the command' => [['name' => 'queue', 'kind' => 'queue', 'command' => 'php a.php && php b.php', 'enabled' => true], 'processes.0.command'],
        'a custom process without a command' => [['name' => 'mailer', 'kind' => 'custom', 'command' => '', 'enabled' => true], 'processes.0.command'],
        'too many copies' => [['name' => 'queue', 'kind' => 'queue', 'enabled' => true, 'replicas' => 11], 'processes.0.replicas'],
        'a grace period of zero' => [['name' => 'queue', 'kind' => 'queue', 'enabled' => true, 'stop_grace_seconds' => 0], 'processes.0.stop_grace_seconds'],
        'a memory limit that is too small' => [['name' => 'queue', 'kind' => 'queue', 'enabled' => true, 'memory_limit_mb' => 4], 'processes.0.memory_limit_mb'],
        'an unknown overlap policy' => [['name' => 'queue', 'kind' => 'queue', 'enabled' => true, 'overlap' => 'sometimes'], 'processes.0.overlap'],
    ]);

    it('refuses the same name twice, and more than twenty processes', function () {
        $this->put('/apps/shop/processes', ['processes' => [($this->row)('queue', 'queue'), ($this->row)('queue', 'queue')]])->assertSessionHasErrors('processes.0.name');
        $this->put('/apps/shop/processes', ['processes' => array_map(fn ($i) => ($this->row)('worker'.chr(97 + $i), 'queue'), range(0, 20))])->assertSessionHasErrors('processes');
    });

    it('records names and on/off in the audit log, never the commands', function () {
        $this->put('/apps/shop/processes', ['processes' => [
            ($this->row)('mailer', 'custom', ['command' => 'php mailer.php --token=hunter2']),
        ]])->assertSessionHasNoErrors();

        $entry = AuditLog::query()->where('action', 'app.processes_changed')->firstOrFail();

        expect($entry->metadata)->toBe(['app' => 'shop', 'processes' => [['name' => 'mailer', 'enabled' => true]]])
            ->and(json_encode($entry->toArray()))->not->toContain('hunter2');
    });

    it('needs the permission to change the app, and a login', function () {
        $this->post('/logout');
        $this->put('/apps/shop/processes', ['processes' => []])->assertRedirect('/login');

        $user = User::factory()->regular()->create();
        $user->givePermissionTo(Permission::findOrCreate('apps.view'));
        $this->actingAs($user);

        $this->put('/apps/shop/processes', ['processes' => [($this->row)('queue', 'queue')]])->assertForbidden();
        expect($this->shop->processes()->count())->toBe(0);
    });
});

describe('the page', function () {
    it('sends the list with the usual command beside the person\'s, and what the inspector said about each', function () {
        $this->shop->update(['detected' => ['services' => [
            ['kind' => 'custom', 'name' => 'reverb', 'detected_by' => 'laravel/reverb', 'default_command' => 'php artisan reverb:start', 'supported' => false, 'note' => 'Needs a public websocket port.'],
        ]]]);
        AppProcess::query()->create(['app_id' => $this->shop->id, 'name' => 'scheduler', 'kind' => ProcessKind::Scheduler, 'enabled' => true, 'overlap' => 'stop_first']);
        AppProcess::query()->create(['app_id' => $this->shop->id, 'name' => 'queue', 'kind' => ProcessKind::Queue, 'command' => 'php artisan queue:work --queue=mail', 'enabled' => false, 'replicas' => 2, 'sort' => 1]);
        AppProcess::query()->create(['app_id' => $this->shop->id, 'name' => 'reverb', 'kind' => ProcessKind::Custom, 'command' => 'php artisan reverb:start', 'enabled' => false, 'sort' => 2, 'detected_by' => 'laravel/reverb']);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
            ->where('app.uses_processes', true)
            ->has('app.processes', 3)
            ->where('app.processes.0.name', 'scheduler')
            ->where('app.processes.0.removable', false)
            ->where('app.processes.0.default_command', 'php artisan schedule:work')
            ->where('app.processes.1.command', 'php artisan queue:work --queue=mail')
            ->where('app.processes.1.default_command', 'php artisan queue:work --tries=3 --timeout=90')
            ->where('app.processes.1.may_replicate', true)
            ->where('app.processes.1.replicas', 2)
            ->where('app.processes.2.supported', false)
            ->where('app.processes.2.note', 'Needs a public websocket port.')
            ->where('app.processes.2.removable', true));
    });

    it('has no services for an app that is not a Laravel app', function () {
        $this->shop->update(['stack' => Stack::Go]);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.uses_processes', false)->where('app.processes', []));
    });
});

describe('the release script', function () {
    beforeEach(function () {
        $this->deploy = fn (): Deployment => app(Deployer::class)->deploy($this->shop->fresh())->fresh();
        $this->task = fn (): TaskSpec => collect($this->host->actions())->where('action', 'runTask')->last()['args']['spec'];
    });

    it('has a usual text for a Laravel app and none for the others', function () {
        expect(ReleaseScripts::defaultFor(Stack::Laravel))->toBe("php artisan migrate --force\n")
            ->and(ReleaseScripts::defaultFor(Stack::Go))->toBeNull()
            ->and(ReleaseScripts::defaultFor(null))->toBeNull();
    });

    it('is saved with the settings, with line breaks made plain, and shown on the page', function () {
        $this->put('/apps/shop', [
            'name' => 'Shop', 'git_url' => 'https://example.com/acme/shop.git', 'git_branch' => 'main', 'container_port' => 8080, 'health_path' => '/up',
            'release_script' => "# first\r\nphp artisan migrate --force\r\nphp artisan optimize  \r\n\r\n",
        ])->assertSessionHasNoErrors();

        expect($this->shop->fresh()->release_script)->toBe("# first\nphp artisan migrate --force\nphp artisan optimize\n");

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.release_script', "# first\nphp artisan migrate --force\nphp artisan optimize\n"));

        $this->put('/apps/shop', ['name' => 'Shop', 'git_url' => 'https://example.com/acme/shop.git', 'git_branch' => 'main', 'container_port' => 8080, 'health_path' => '/up', 'release_script' => "  \n"])->assertSessionHasNoErrors();

        expect($this->shop->fresh()->release_script)->toBeNull();
    });

    it('refuses a script that is too long', function () {
        $this->put('/apps/shop', [
            'name' => 'Shop', 'git_url' => 'https://example.com/acme/shop.git', 'git_branch' => 'main', 'container_port' => 8080, 'health_path' => '/up',
            'release_script' => str_repeat('a', ReleaseScripts::MAX_LENGTH + 1),
        ])->assertSessionHasErrors('release_script');
    });

    it('runs as one task with sh -ec before the web container starts, with the app\'s environment', function () {
        $this->shop->update(['release_script' => "php artisan migrate --force\nphp artisan storage:link\n", 'memory_limit_mb' => 256]);

        $deployment = ($this->deploy)();

        expect($deployment->status)->toBe(DeploymentStatus::Active)
            ->and($deployment->release_script)->toBe("php artisan migrate --force\nphp artisan storage:link\n")
            ->and($deployment->log)->toContain('Running the release script:')->toContain('php artisan storage:link')->toContain('The release script finished.')
            ->and(collect($this->host->actions())->pluck('action')->all())->toBe(['buildImage', 'runTask', 'runContainer']);

        $task = ($this->task)();

        expect($task->command)->toBe(['sh', '-ec', "php artisan migrate --force\nphp artisan storage:link\n"])
            ->and($task->name)->toBe('shop-1-release')
            ->and($task->image)->toBe('commypanel/shop:1')
            ->and($task->env['PORT'])->toBe('8080')
            ->and($task->memoryLimitMb)->toBe(256);
    });

    it('wins over the single release command', function () {
        $this->shop->update(['release_script' => 'php artisan migrate --force', 'release_command' => 'echo old']);

        $deployment = ($this->deploy)();

        expect(($this->task)()->command)->toBe(['sh', '-ec', 'php artisan migrate --force'])->and($deployment->release_command)->toBe('echo old');
    });

    it('stops the deployment when a line fails, before the new version starts', function () {
        $first = ($this->deploy)();
        $this->shop->update(['release_script' => 'php artisan migrate --force']);
        $this->host->taskExitsWith(1);

        $second = ($this->deploy)();

        expect($second->status)->toBe(DeploymentStatus::Failed)
            ->and($second->error)->toBe('The release script failed with exit code 1. The new version was not started.')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($first->id)
            ->and($this->host->containers())->toHaveKey('shop-1')->not->toHaveKey('shop-2');
    });

    it('is not run again by a rollback, and a deployment without one runs no task', function () {
        $first = ($this->deploy)();
        expect(collect($this->host->actions())->where('action', 'runTask'))->toHaveCount(0);

        $this->shop->update(['release_script' => 'php artisan migrate --force']);
        ($this->deploy)();

        $rollback = app(Deployer::class)->rollback($this->shop->fresh(), $first)->fresh();

        expect($rollback->release_script)->toBeNull()->and(collect($this->host->actions())->where('action', 'runTask'))->toHaveCount(1);
    });

    it('is shown on the deployment page as a release step', function () {
        $this->shop->update(['release_script' => 'php artisan migrate --force']);
        $deployment = ($this->deploy)();

        $this->get("/apps/shop/deployments/{$deployment->number}")->assertInertia(fn (Assert $page) => $page->where('deployment.has_release_command', true));
    });
});

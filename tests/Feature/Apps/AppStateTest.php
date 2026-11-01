<?php

use App\Deploy\Source\UploadStorage;
use App\Enums\AppStatus;
use App\Enums\DeploymentStatus;
use App\Enums\SourceType;
use App\Host\Data\ContainerSpec;
use App\Host\Data\NginxSite;
use App\Host\HostDriver;
use App\Jobs\RunDeployment;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\Domain;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\Archives;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
});

function runningApp(): App
{
    $app = App::factory()->create(['slug' => 'shop']);
    $deployment = Deployment::factory()->create([
        'app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'host_port' => 20001,
        'container_name' => 'shop-1', 'image_tag' => 'commypanel/shop:1', 'image_available' => true,
    ]);
    $app->update(['active_deployment_id' => $deployment->id]);
    Domain::query()->create(['name' => 'shop.example.com', 'app_id' => $app->id]);

    return $app->refresh();
}

describe('deactivate', function () {
    it('removes the container and the nginx site but keeps everything else', function () {
        $app = runningApp();
        $this->host->applyNginxSite(new NginxSite('shop', ['shop.example.com'], 20001));

        $this->post('/apps/shop/deactivate')->assertSessionHasNoErrors();

        $app->refresh();
        expect($app->status())->toBe(AppStatus::Deactivated)
            ->and($app->active_deployment_id)->toBeNull()
            ->and($app->domains()->count())->toBe(1)
            ->and($app->deployments()->first()->status)->toBe(DeploymentStatus::Stopped)
            ->and($app->deployments()->first()->image_available)->toBeTrue()
            ->and($this->host->sites())->toBe([])
            ->and(collect($this->host->actions())->where('action', 'removeContainer')->pluck('args.name')->all())->toBe(['shop-1'])
            ->and(AuditLog::query()->where('action', 'app.deactivated')->exists())->toBeTrue();
    });

    it('refuses an app that is not running or is mid-deployment', function () {
        $idle = App::factory()->create(['slug' => 'idle']);
        $this->post('/apps/idle/deactivate')->assertSessionHasErrors('state');

        $app = runningApp();
        Deployment::factory()->create(['app_id' => $app->id, 'number' => 2, 'status' => DeploymentStatus::Building]);
        $this->post('/apps/shop/deactivate')->assertSessionHasErrors('state');

        expect($app->refresh()->active_deployment_id)->not->toBeNull();
    });
});

describe('activate', function () {
    it('starts the stopped release again without rebuilding', function () {
        Queue::fake();
        $app = runningApp();
        $this->post('/apps/shop/deactivate');

        $this->post('/apps/shop/activate')->assertRedirect('/apps/shop/deployments/2');

        $new = $app->deployments()->where('number', 2)->firstOrFail();
        expect($new->image_tag)->toBe('commypanel/shop:1')
            ->and($new->rolled_back_from_id)->not->toBeNull();
        Queue::assertPushed(RunDeployment::class);
    });

    it('needs a stopped release, and refuses an app that is already running', function () {
        Queue::fake();
        $this->post('/apps/'.App::factory()->create(['slug' => 'idle'])->slug.'/activate')->assertSessionHasErrors('state');

        runningApp();
        $this->post('/apps/shop/activate')->assertSessionHasErrors('state');

        Queue::assertNothingPushed();
    });

    it('says so when the stopped image has been removed', function () {
        $app = runningApp();
        $this->post('/apps/shop/deactivate');
        $app->deployments()->update(['image_available' => false]);

        $this->post('/apps/shop/activate')->assertSessionHasErrors('state');
    });
});

describe('stop, start and restart', function () {
    beforeEach(function () {
        $this->shop = runningApp();
        $this->host->applyNginxSite(new NginxSite('shop', ['shop.example.com'], 20001));
        $this->host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));
    });

    it('stops the live container but keeps the release live and the domains configured', function () {
        $this->post('/apps/shop/stop')->assertSessionHasNoErrors()->assertSessionHas('status');

        $this->shop->refresh();
        expect($this->shop->status())->toBe(AppStatus::Stopped)
            ->and($this->shop->stopped_at)->not->toBeNull()
            ->and($this->shop->active_deployment_id)->not->toBeNull()
            ->and($this->host->sites())->toHaveKey('shop')
            ->and($this->host->containers())->toHaveKey('shop-1')
            ->and($this->host->listContainers()[0]->state)->toBe('exited')
            ->and(AuditLog::query()->where('action', 'app.stopped')->exists())->toBeTrue();

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.status', 'stopped'));
    });

    it('starts a stopped app again without a deployment', function () {
        Queue::fake();
        $this->post('/apps/shop/stop');

        $this->post('/apps/shop/start')->assertSessionHasNoErrors();

        expect($this->shop->refresh()->status())->toBe(AppStatus::Running)
            ->and($this->host->listContainers()[0]->state)->toBe('running')
            ->and(AuditLog::query()->where('action', 'app.started')->exists())->toBeTrue();
        Queue::assertNothingPushed();
    });

    it('restarts a running app', function () {
        $this->post('/apps/shop/restart')->assertSessionHasNoErrors();

        expect(collect($this->host->actions())->where('action', 'restartContainer')->pluck('args.name')->all())->toBe(['shop-1'])
            ->and(AuditLog::query()->where('action', 'app.restarted')->exists())->toBeTrue();
    });

    it('refuses actions that do not fit the current state', function () {
        $this->post('/apps/shop/start')->assertSessionHasErrors(['state' => 'The app is already running.']);

        $this->post('/apps/shop/stop');
        $this->post('/apps/shop/stop')->assertSessionHasErrors(['state' => 'The app is already stopped.']);
        $this->post('/apps/shop/restart')->assertSessionHasErrors('state');

        App::factory()->create(['slug' => 'idle']);
        $this->post('/apps/idle/stop')->assertSessionHasErrors(['state' => 'This app has no live release. Deploy it first.']);
    });

    it('waits for a running deployment', function () {
        Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Building]);

        $this->post('/apps/shop/stop')->assertSessionHasErrors('state');
        expect($this->shop->refresh()->stopped_at)->toBeNull();
    });

    it('keeps the app running when the agent fails', function () {
        $this->host->failOn('stopContainer');

        $this->post('/apps/shop/stop')->assertSessionHasErrors('state');
        expect($this->shop->refresh()->status())->toBe(AppStatus::Running);
    });

    it('clears the stopped state when the app is deactivated', function () {
        $this->post('/apps/shop/stop');
        $this->post('/apps/shop/deactivate')->assertSessionHasNoErrors();

        $this->shop->refresh();
        expect($this->shop->stopped_at)->toBeNull()
            ->and($this->shop->status())->toBe(AppStatus::Deactivated);
    });
});

it('shows the state on the app page', function () {
    $app = runningApp();
    $this->post('/apps/shop/deactivate');

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
        ->where('app.status', 'deactivated')
        ->where('app.deactivated_release', 1)
        ->where('app.url', null));
});

it('links to the live app through its domain, not a redirecting one', function () {
    $app = runningApp();
    Domain::query()->where('name', 'shop.example.com')->update(['redirect_to' => 'www.shop.example.com']);
    Domain::query()->create(['name' => 'www.shop.example.com', 'app_id' => $app->id]);

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page->where('app.url', 'http://www.shop.example.com'));
});

describe('env import', function () {
    it('saves pasted variables and reports what it skipped', function () {
        $app = App::factory()->create(['slug' => 'shop']);

        $this->post('/apps/shop/env/import', ['env' => "A=1\n# c\nB=\"two words\"\nPORT=9\nnot valid"])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', fn ($status) => str_contains($status, '2 variable(s)') && str_contains($status, '2 line(s) were skipped'));

        expect(AppEnvVar::query()->where('app_id', $app->id)->pluck('value', 'key')->all())->toBe(['A' => '1', 'B' => 'two words']);
    });

    it('overwrites existing values and refuses text without variables', function () {
        $app = App::factory()->create(['slug' => 'shop']);
        AppEnvVar::query()->create(['app_id' => $app->id, 'key' => 'A', 'value' => 'old']);

        $this->post('/apps/shop/env/import', ['env' => 'A=new']);
        expect(AppEnvVar::query()->where('key', 'A')->first()->value)->toBe('new');

        $this->post('/apps/shop/env/import', ['env' => "# nothing here\n"])->assertSessionHasErrors('env');
    });
});

it('records the commit message of a deployment and keeps it on rollback', function () {
    $app = runningApp();
    $first = $app->deployments()->first();
    $first->update(['commit_sha' => 'abcdef1234', 'commit_message' => 'Fix the checkout']);

    $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
        ->where('deployments.0.commit', 'abcdef12')
        ->where('deployments.0.commit_message', 'Fix the checkout'));
});

describe('upload apps', function () {
    beforeEach(function () {
        config(['commypanel.data_path' => storage_path('framework/testing/state-'.bin2hex(random_bytes(4)))]);
        $this->zipped = App::factory()->create(['slug' => 'zipped', 'source_type' => SourceType::Upload, 'git_url' => null]);
    });

    afterEach(fn () => File::deleteDirectory((string) config('commypanel.data_path')));

    it('saves settings without a Git address', function () {
        $this->patch('/apps/zipped', ['name' => 'Zipped 2', 'container_port' => 3000, 'health_path' => '/'])
            ->assertSessionHasNoErrors();

        expect($this->zipped->fresh())->name->toBe('Zipped 2')->container_port->toBe(3000);
    });

    it('replaces the project with a new upload', function () {
        $path = Archives::zip(storage_path('framework/testing/new-'.bin2hex(random_bytes(3)).'.zip'), ['main.go' => 'package main']);

        $this->post('/apps/zipped/source', ['archive' => new UploadedFile($path, 'a.zip', null, null, true)])->assertSessionHasNoErrors();

        expect(file_get_contents(app(UploadStorage::class)->path($this->zipped).'/main.go'))->toBe('package main');
        File::delete($path);
    });

    it('is not available for Git apps', function () {
        App::factory()->create(['slug' => 'gitty']);

        $this->post('/apps/gitty/source', ['archive' => UploadedFile::fake()->create('a.zip', 1)])->assertNotFound();
    });
});

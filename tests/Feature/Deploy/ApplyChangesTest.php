<?php

use App\Auth\SyncRolesAndPermissions;
use App\Deploy\Deployer;
use App\Deploy\DeploymentFailed;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Enums\DeploymentStatus;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppEnvVar;
use App\Models\AppVolume;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();
    app(SyncRolesAndPermissions::class)();

    $this->health = new FakeHealthChecker;
    $this->traffic = new RecordingTrafficSwitcher;
    $this->host = app(HostDriver::class);
    app()->instance(SourceFetcher::class, new FakeSourceFetcher);
    app()->instance(HealthChecker::class, $this->health);
    app()->instance(TrafficSwitcher::class, $this->traffic);

    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->live = app(Deployer::class)->deploy($this->shop)->fresh();
    $this->pending = fn () => $this->shop->fresh()->load('activeDeployment')->hasPendingChanges();
    $this->later = fn () => Carbon::setTestNow(now()->addMinute());
});

afterEach(function () {
    Carbon::setTestNow();
    File::deleteDirectory($this->dataPath);
});

describe('knowing there is something to apply', function () {
    it('is clean after a deployment and flags a changed variable, volume or link', function () {
        expect(($this->pending)())->toBeFalse();

        ($this->later)();
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'MODE', 'value' => 'fast']);
        expect(($this->pending)())->toBeTrue();
    });

    it('flags a volume and its removal, and a removed variable', function () {
        ($this->later)();
        $volume = AppVolume::query()->create(['app_id' => $this->shop->id, 'name' => 'uploads', 'path' => '/data']);
        expect(($this->pending)())->toBeTrue();

        // applied: clean again
        app(Deployer::class)->apply($this->shop->fresh()->load('activeDeployment'));
        expect(($this->pending)())->toBeFalse();

        ($this->later)();
        ($this->later)();
        $volume->delete();
        expect(($this->pending)())->toBeTrue();
    });

    it('does not flag an app that has not been deployed', function () {
        $fresh = App::factory()->create(['slug' => 'new']);
        AppEnvVar::query()->create(['app_id' => $fresh->id, 'key' => 'A', 'value' => 'b']);

        expect($fresh->fresh()->load('activeDeployment')->hasPendingChanges())->toBeFalse();
    });
});

describe('applying', function () {
    it('recreates the container from the live image with the new variables, without building', function () {
        ($this->later)();
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'MODE', 'value' => 'fast']);
        $builds = collect($this->host->actions())->where('action', 'buildImage')->count();

        $apply = app(Deployer::class)->apply($this->shop->fresh()->load('activeDeployment'), $this->user)->fresh();

        expect($apply->status)->toBe(DeploymentStatus::Active)
            ->and($apply->settings_only)->toBeTrue()
            ->and($apply->image_tag)->toBe($this->live->image_tag)
            ->and($apply->number)->toBe($this->live->number + 1)
            ->and($apply->log)->toContain('Recreating the container')
            ->and($this->shop->fresh()->active_deployment_id)->toBe($apply->id)
            ->and($this->live->fresh()->status)->toBe(DeploymentStatus::Superseded)
            ->and(collect($this->host->actions())->where('action', 'buildImage')->count())->toBe($builds)
            ->and(collect($this->host->actions())->where('action', 'runContainer'))->toHaveCount(2)
            ->and($this->traffic->switched)->toHaveCount(2)
            ->and(AuditLog::query()->where('action', 'deploy.apply_queued')->exists())->toBeTrue();

        $spec = collect($this->host->actions())->where('action', 'runContainer')->last()['args']['spec'];
        expect($spec->env['MODE'])->toBe('fast');
    });

    it('keeps the old container serving when the new one is not healthy', function () {
        ($this->later)();
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'MODE', 'value' => 'broken']);
        $this->health->healthy = false;

        $apply = app(Deployer::class)->apply($this->shop->fresh()->load('activeDeployment'))->fresh();

        expect($apply->status)->toBe(DeploymentStatus::Failed)
            ->and($this->shop->fresh()->active_deployment_id)->toBe($this->live->id)
            ->and($this->live->fresh()->status)->toBe(DeploymentStatus::Active);
    });

    it('is not offered when nothing is live, and is not a rollback in what people see', function () {
        $fresh = App::factory()->create(['slug' => 'blank']);
        expect(fn () => app(Deployer::class)->apply($fresh->load('activeDeployment')))->toThrow(DeploymentFailed::class);

        $apply = app(Deployer::class)->apply($this->shop->fresh()->load('activeDeployment'))->fresh();
        $this->get('/apps/shop')->assertInertia(fn ($page) => $page
            ->where('deployments.0.is_apply', true)->where('deployments.0.is_rollback', false));
        expect($apply->rolled_back_from_id)->toBe($this->live->id);
    });
});

describe('the button', function () {
    it('applies through the page for people who may deploy, and shows the banner only when needed', function () {
        $this->get('/apps/shop')->assertInertia(fn ($page) => $page->where('app.pending_changes', false));

        ($this->later)();
        AppEnvVar::query()->create(['app_id' => $this->shop->id, 'key' => 'MODE', 'value' => 'fast']);
        $this->get('/apps/shop')->assertInertia(fn ($page) => $page->where('app.pending_changes', true));

        $this->post('/apps/shop/apply')->assertRedirect();
        expect(Deployment::query()->where('settings_only', true)->count())->toBe(1);
        $this->get('/apps/shop')->assertInertia(fn ($page) => $page->where('app.pending_changes', false));
    });

    it('needs the deploy permission and refuses while a deployment is running', function () {
        $viewer = User::factory()->regular()->create();
        $viewer->givePermissionTo('apps.view');
        $this->actingAs($viewer)->post('/apps/shop/apply')->assertForbidden();

        Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 99, 'status' => DeploymentStatus::Building]);
        $this->actingAs($this->user)->post('/apps/shop/apply')->assertSessionHasErrors('deploy');
    });
});

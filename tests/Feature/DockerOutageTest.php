<?php

use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Data\SystemStatus;
use App\Host\Drivers\FakeHostDriver;
use App\Host\Exceptions\HostOperationFailed;
use App\Host\HostDriver;
use App\Host\HostHealth;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    Cache::flush();
});

describe('the status every page gets', function () {
    it('says the agent and Docker are up', function () {
        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('hostHealth', ['agent' => true, 'docker' => true]));
    });

    it('says when Docker is down', function () {
        $this->host->dockerGoesDown();

        $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
            ->where('hostHealth', ['agent' => true, 'docker' => false]));
    });

    it('says when the agent cannot be reached, without failing the page', function () {
        app()->instance(HostDriver::class, new class extends FakeHostDriver
        {
            public function systemStatus(): SystemStatus
            {
                throw new HostOperationFailed('The CommyPanel agent is not reachable.');
            }
        });

        $this->get('/apps')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('hostHealth', ['agent' => false, 'docker' => false]));
    });

    it('asks the agent at most once per short interval', function () {
        $calls = 0;
        app()->instance(HostDriver::class, new class($calls) extends FakeHostDriver
        {
            public function __construct(private int &$calls) {}

            public function systemStatus(): SystemStatus
            {
                $this->calls++;

                return parent::systemStatus();
            }
        });

        $this->get('/dashboard');
        $this->get('/apps');

        // The dashboard asks for itself once; the shared status adds one cached call.
        expect($calls)->toBe(2);
    });

    it('is not shared with guests', function () {
        auth()->logout();

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->where('hostHealth', null));
    });
});

describe('the Docker page while Docker is down', function () {
    it('shows what systemd knows', function () {
        $this->host->dockerGoesDown();

        $this->get('/server/docker')->assertInertia(fn (Assert $page) => $page
            ->where('docker.available', false)
            ->where('diagnosis.service', 'failed')
            ->where('diagnosis.socket', 'active')
            ->where('diagnosis.journal', fn ($journal) => str_contains($journal, 'failed to start daemon')));
    });

    it('has no diagnosis while Docker runs', function () {
        $this->get('/server/docker')->assertInertia(fn (Assert $page) => $page->where('diagnosis', null));
    });
});

describe('restarting Docker', function () {
    it('needs a recent password confirmation', function () {
        $this->host->dockerGoesDown();

        $this->post('/server/docker/restart')->assertRedirect('/user/confirm-password');

        expect($this->host->actionNames())->not->toContain('restartDocker');
    });

    it('restarts Docker, records it and refreshes the status', function () {
        $this->host->dockerGoesDown();
        app(HostHealth::class)->status();

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/server/docker/restart')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status');

        expect($this->host->actionNames())->toContain('restartDocker')
            ->and(app(HostHealth::class)->status()['docker'])->toBeTrue()
            ->and(AuditLog::query()->where('action', 'docker.restarted')->exists())->toBeTrue();
    });

    it('reports a failed restart', function () {
        $this->host->failOn('restartDocker');

        $this->withSession(['auth.password_confirmed_at' => time()])
            ->post('/server/docker/restart')
            ->assertSessionHasErrors('docker');

        expect(AuditLog::query()->where('action', 'docker.restart_failed')->exists())->toBeTrue();
    });
});

it('explains a Docker outage when an app is stopped or restarted', function () {
    $app = App::factory()->create(['slug' => 'shop']);
    $live = Deployment::factory()->create(['app_id' => $app->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'container_name' => 'shop-1', 'host_port' => 20001]);
    $app->update(['active_deployment_id' => $live->id]);
    $this->host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));
    $this->host->dockerGoesDown();

    $this->post('/apps/shop/restart')
        ->assertSessionHasErrors(['state' => 'Could not restart the app: Docker is not running on this server. See Server > Docker.']);
});

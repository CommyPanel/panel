<?php

use App\Deploy\AppVolumes;
use App\Deploy\Deployer;
use App\Deploy\Health\HealthChecker;
use App\Deploy\Source\SourceFetcher;
use App\Deploy\Traffic\TrafficSwitcher;
use App\Host\Data\ContainerSpec;
use App\Host\Data\TaskSpec;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\AppVolume;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHealthChecker;
use Tests\Support\FakeSourceFetcher;
use Tests\Support\RecordingTrafficSwitcher;

beforeEach(function () {
    $this->dataPath = storage_path('framework/testing/data-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dataPath, 'commypanel.deploy.drain_seconds' => 0]);
    Sleep::fake();

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    $this->shop = App::factory()->create(['slug' => 'shop', 'stack' => null, 'container_port' => 8080, 'health_path' => '/health']);
});

afterEach(fn () => File::deleteDirectory($this->dataPath));

describe('choosing what to keep', function () {
    it('adds a path, cleaning it up and naming the folder after it', function () {
        $this->post('/apps/shop/volumes', ['path' => ' /app/data/ '])->assertSessionHasNoErrors()->assertSessionHas('status');

        $volume = $this->shop->volumes()->first();

        expect($volume->path)->toBe('/app/data')
            ->and($volume->name)->toBe('app-data')
            ->and($volume->hostPath($this->shop))->toBe("{$this->dataPath}/volumes/shop/app-data")
            ->and(AuditLog::query()->where('action', 'app.volume_added')->count())->toBe(1);
    });

    it('keeps folder names unique when two paths would give the same one', function () {
        $this->post('/apps/shop/volumes', ['path' => '/app/data']);
        $this->post('/apps/shop/volumes', ['path' => '/app-data']);
        $this->post('/apps/shop/volumes', ['path' => '/app_data']);

        expect($this->shop->volumes()->orderBy('id')->pluck('name')->all())->toBe(['app-data', 'app-data-2', 'app-data-3']);
    });

    it('refuses paths that are wrong or dangerous', function (string $path) {
        $this->post('/apps/shop/volumes', ['path' => $path])->assertSessionHasErrors('path');

        expect($this->shop->volumes()->count())->toBe(0);
    })->with([
        'relative' => 'data',
        'the root' => '/',
        'parent folders' => '/app/../etc',
        'double slash' => '/app//data',
        'spaces' => '/my data',
        'a shell trick' => '/data;rm -rf /',
        'proc' => '/proc',
        'under sys' => '/sys/kernel',
        'under dev' => '/dev/shm',
        'too long' => '/'.str_repeat('a', 260),
    ]);

    it('refuses the same path twice and more than eight volumes', function () {
        $this->post('/apps/shop/volumes', ['path' => '/data']);
        $this->post('/apps/shop/volumes', ['path' => '/data/'])->assertSessionHasErrors('path');

        foreach (range(1, 7) as $n) {
            $this->post('/apps/shop/volumes', ['path' => "/v{$n}"])->assertSessionHasNoErrors();
        }

        $this->post('/apps/shop/volumes', ['path' => '/one-too-many'])->assertSessionHasErrors('path');
        expect($this->shop->volumes()->count())->toBe(AppVolumes::MAX);
    });

    it('removes a volume from the app but leaves its files on the server', function () {
        $volume = $this->shop->volumes()->create(['name' => 'data', 'path' => '/data']);

        $this->delete("/apps/shop/volumes/{$volume->id}")->assertSessionHas('status');

        expect($this->shop->volumes()->count())->toBe(0)
            ->and($this->host->actionNames())->not->toContain('removeVolumes')
            ->and(AuditLog::query()->where('action', 'app.volume_removed')->count())->toBe(1);
    });

    it('cannot remove another app\'s volume through this app\'s address', function () {
        $other = App::factory()->create(['slug' => 'blog']);
        $theirs = $other->volumes()->create(['name' => 'data', 'path' => '/data']);

        $this->delete("/apps/shop/volumes/{$theirs->id}")->assertNotFound();

        expect(AppVolume::query()->count())->toBe(1);
    });

    it('lists the volumes on the app page', function () {
        $this->shop->volumes()->create(['name' => 'app-data', 'path' => '/app/data']);

        $this->get('/apps/shop')->assertInertia(fn (Assert $page) => $page
            ->where('app.volumes.0.path', '/app/data')
            ->where('app.volumes.0.name', 'app-data'));
    });
});

describe('using them', function () {
    beforeEach(function () {
        app()->instance(SourceFetcher::class, new FakeSourceFetcher);
        app()->instance(HealthChecker::class, new FakeHealthChecker);
        app()->instance(TrafficSwitcher::class, new RecordingTrafficSwitcher);

        $this->shop->volumes()->create(['name' => 'uploads', 'path' => '/app/uploads']);
        $this->shop->volumes()->create(['name' => 'cache', 'path' => '/app/cache']);
    });

    it('mounts every volume into the new container', function () {
        app(Deployer::class)->deploy($this->shop);

        /** @var ContainerSpec $spec */
        $spec = collect($this->host->actions())->firstWhere('action', 'runContainer')['args']['spec'];

        expect(collect($spec->volumes)->map(fn ($v) => [$v->source, $v->target, $v->readOnly])->all())->toBe([
            ["{$this->dataPath}/volumes/shop/uploads", '/app/uploads', false],
            ["{$this->dataPath}/volumes/shop/cache", '/app/cache', false],
        ]);
    });

    it('gives the release command the same volumes', function () {
        $this->shop->update(['release_command' => 'npm run migrate']);

        app(Deployer::class)->deploy($this->shop);

        /** @var TaskSpec $task */
        $task = collect($this->host->actions())->firstWhere('action', 'runTask')['args']['spec'];

        expect(collect($task->volumes)->pluck('target')->all())->toBe(['/app/uploads', '/app/cache']);
    });

    it('mounts the same folder in the next release, so the data is shared across the switch', function () {
        app(Deployer::class)->deploy($this->shop);
        app(Deployer::class)->deploy($this->shop);

        $specs = collect($this->host->actions())->where('action', 'runContainer')->pluck('args.spec');

        expect($specs)->toHaveCount(2)
            ->and($specs[0]->name)->not->toBe($specs[1]->name)
            ->and($specs[0]->volumes[0]->source)->toBe($specs[1]->volumes[0]->source);
    });
});

it('deletes the volume data together with the app', function () {
    $this->shop->volumes()->create(['name' => 'data', 'path' => '/data']);

    $this->delete('/apps/shop', ['confirm' => 'shop'])->assertRedirect('/apps');

    $removed = collect($this->host->actions())->firstWhere('action', 'removeVolumes');

    expect($removed['args'])->toBe(['app' => 'shop', 'name' => null])
        // After the containers, because nothing may still have the folders mounted.
        ->and($this->host->actionNames())->toBe(['removeVolumes', 'removeNginxSite', 'removeCertificate']);
});

<?php

use App\Host\BiggestVolumes;
use App\Host\Data\VolumeUsage;
use App\Host\DiskWarning;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Models\App;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia as Assert;

const GB = 1024 ** 3;

beforeEach(function () {
    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    Cache::flush();
});

it('has three levels by how full the disk is', function (int $freeGb, string $level, int $percent) {
    $warning = DiskWarning::of(100 * GB, $freeGb * GB);

    expect($warning->level)->toBe($level)
        ->and($warning->usedPercent)->toBe($percent);
})->with([
    'plenty of room' => [50, 'ok', 50],
    'just under the warning' => [16, 'ok', 84],
    'at the warning' => [15, 'warn', 85],
    'at the critical level' => [5, 'critical', 95],
    'nothing left' => [0, 'critical', 100],
]);

it('says nothing when the agent does not report the size of the disk', function () {
    expect(DiskWarning::of(0, 10 * GB))->toBeNull();
});

it('follows the configured levels', function () {
    config(['commypanel.disk.warn_percent' => 60, 'commypanel.disk.critical_percent' => 70]);

    expect(DiskWarning::of(100 * GB, 35 * GB)->level)->toBe('warn')
        ->and(DiskWarning::of(100 * GB, 25 * GB)->level)->toBe('critical');
});

it('gives every page the level of the disk', function () {
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->host->setDiskFree(8 * GB);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page
        ->where('hostHealth.disk.level', 'warn')
        ->where('hostHealth.disk.used_percent', 92)
        ->where('hostHealth.disk.free_bytes', 8 * GB));
});

it('lists the biggest volumes on the server page, largest first', function () {
    $small = App::factory()->create(['slug' => 'small']);
    $big = App::factory()->create(['slug' => 'big']);

    app()->instance(HostDriver::class, new class extends FakeHostDriver
    {
        public function listVolumes(string $app): array
        {
            return match ($app) {
                'small' => [new VolumeUsage('data', 10 * 1024 ** 2, 5)],
                'big' => [new VolumeUsage('uploads', 3 * 1024 ** 3, 900, partial: true)],
                default => [],
            };
        }
    });

    $this->get('/server')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('server/overview')
        ->where('volumes.0', ['app' => 'big', 'name' => 'uploads', 'bytes' => 3 * 1024 ** 3, 'files' => 900, 'partial' => true])
        ->where('volumes.1.app', 'small'));
});

it('remembers the measurement for a few minutes, and measures again on request', function () {
    $calls = 0;
    App::factory()->create(['slug' => 'shop']);
    app()->instance(HostDriver::class, new class($calls) extends FakeHostDriver
    {
        public function __construct(private int &$calls) {}

        public function listVolumes(string $app): array
        {
            $this->calls++;

            return [];
        }
    });

    $volumes = app(BiggestVolumes::class);
    $volumes->top();
    $volumes->top();
    expect($calls)->toBe(1);

    $volumes->top(fresh: true);
    expect($calls)->toBe(2);
});

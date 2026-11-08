<?php

use App\Audit\Severity;
use App\Backups\VolumeBackups;
use App\Console\Commands\BackupCommand;
use App\Enums\DeploymentStatus;
use App\Host\Data\ContainerSpec;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\BackupVolumeJob;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\Deployment;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/volrestore-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dir, 'commypanel.backups.keep_volumes' => 1]);
    Sleep::fake();
    Cache::flush();

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $this->volume = $this->shop->volumes()->create(['name' => 'uploads', 'path' => '/app/uploads']);
    $live = Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 1, 'status' => DeploymentStatus::Active, 'container_name' => 'shop-1', 'host_port' => 20001]);
    $this->shop->update(['active_deployment_id' => $live->id]);
    $this->host->runContainer(new ContainerSpec('shop-1', 'commypanel/shop:1', 20001, 8080));

    $this->archive = app(VolumeBackups::class)->create($this->shop, 'uploads')['name'];
    $this->url = "/apps/shop/volume-backups/{$this->archive}/restore";
    $this->names = fn () => $this->host->actionNames();
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('needs the password and the typed volume name, and is recorded as dangerous', function () {
    $this->post($this->url, ['confirm' => 'uploads'])->assertRedirect();
    ($this->confirmed)()->post($this->url, ['confirm' => 'nope'])->assertSessionHasErrors('volume');
    expect($this->host->actionNames())->not->toContain('restoreVolume');

    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads'])->assertSessionHas('status');

    $entry = AuditLog::query()->where('action', 'volume.restored')->first();

    expect($entry->metadata)->toBe(['app' => 'shop', 'volume' => 'uploads', 'file' => $this->archive])
        ->and(Severity::of('volume.restored'))->toBe('danger')
        ->and(Severity::of('volume.restore_failed'))->toBe('warning');
});

it('makes a safety archive, stops the app, restores and starts it again', function () {
    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads']);

    $names = array_values(array_filter($this->host->actionNames(), fn ($n) => in_array($n, ['backupVolume', 'stopContainer', 'restoreVolume', 'startContainer'], true)));
    $restore = collect($this->host->actions())->firstWhere('action', 'restoreVolume');
    $json = $this->getJson('/apps/shop/volume-backups')->json('restores.uploads');

    // The first backupVolume is the one made in beforeEach; the safety archive keeps the one being restored.
    expect($names)->toBe(['backupVolume', 'backupVolume', 'stopContainer', 'restoreVolume', 'startContainer'])
        ->and($restore['args'])->toBe(['app' => 'shop', 'volume' => 'uploads', 'archive' => $this->archive])
        ->and(count(app(VolumeBackups::class)->all($this->shop)))->toBe(2)
        ->and($json['done'])->toContain('app was started again')
        ->and($this->shop->fresh()->stopped_at)->toBeNull();
});

it('starts the app again when the restore fails, and says so', function () {
    $this->host->failVolumeRestoresWith('Agent error: the disk is full');

    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads']);

    $json = $this->getJson('/apps/shop/volume-backups')->json('restores.uploads');

    expect($this->host->actionNames())->toContain('stopContainer')->toContain('startContainer')
        ->and($json['failed'])->toContain('the disk is full')->toContain('started again')
        ->and($this->shop->fresh()->stopped_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'volume.restore_failed')->exists())->toBeTrue();
});

it('shows a plain sentence for an archive from before the agent recorded them', function () {
    $this->host->failVolumeRestoresWith('Agent error: this file was not made by CommyPanel, or was changed since, so it is not restored');

    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads']);

    $this->getJson('/apps/shop/volume-backups')->assertJsonPath('restores.uploads.failed', 'This backup was made before CommyPanel 0.8.0 recorded its backups, so it cannot be restored from here. Make a new backup and use that one.');
});

it('does not stop an app that was not running', function () {
    $this->shop->update(['stopped_at' => now()]);

    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads']);

    expect($this->host->actionNames())->not->toContain('stopContainer')->not->toContain('startContainer')->toContain('restoreVolume');
});

it('refuses a locked app or volume, a running deployment and a made-up archive', function () {
    $this->shop->forceFill(['locked_at' => now()])->save();
    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads'])->assertSessionHasErrors('volume');
    $this->shop->forceFill(['locked_at' => null])->save();

    $this->volume->forceFill(['locked_at' => now()])->save();
    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads'])->assertSessionHasErrors('volume');
    $this->volume->forceFill(['locked_at' => null])->save();

    Deployment::factory()->create(['app_id' => $this->shop->id, 'number' => 2, 'status' => DeploymentStatus::Building]);
    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads'])->assertSessionHasErrors('volume');

    ($this->confirmed)()->post('/apps/shop/volume-backups/uploads-20200101-000000.tar.gz/restore', ['confirm' => 'uploads'])->assertSessionHasErrors('volume');

    expect($this->host->actionNames())->not->toContain('restoreVolume');
});

it('needs the volumes permission', function () {
    $this->actingAs(User::factory()->create(['is_super' => false]));

    ($this->confirmed)()->post($this->url, ['confirm' => 'uploads'])->assertForbidden();
});

describe('the nightly switch', function () {
    it('is off by default and changed with apps.update, recorded', function () {
        expect($this->volume->fresh()->nightly_backup)->toBeFalse();

        $this->put("/apps/shop/volumes/{$this->volume->id}/nightly", ['nightly' => true])->assertSessionHas('status');

        expect($this->volume->fresh()->nightly_backup)->toBeTrue()
            ->and(AuditLog::query()->where('action', 'app.volume_nightly_on')->exists())->toBeTrue();

        $this->actingAs(User::factory()->create(['is_super' => false]));
        $this->put("/apps/shop/volumes/{$this->volume->id}/nightly", ['nightly' => false])->assertForbidden();
    });

    it('archives volumes with it on and leaves the others', function () {
        Queue::fake();
        $this->host->putVolumeFile('shop', 'uploads', 'a.txt', 'x');
        $this->host->putVolumeFile('shop', 'data', 'a.txt', 'x');
        $this->shop->volumes()->create(['name' => 'data', 'path' => '/app/data']);
        $this->volume->forceFill(['nightly_backup' => true])->save();

        Artisan::call('commypanel:backup');

        Queue::assertPushed(BackupVolumeJob::class, 1);
        Queue::assertPushed(BackupVolumeJob::class, fn ($j) => $j->volume === 'uploads');
    });

    it('skips a volume that has no folder yet, and runs on the schedule', function () {
        Queue::fake();
        $this->volume->forceFill(['nightly_backup' => true])->save();

        Artisan::call('commypanel:backup');
        Queue::assertNotPushed(BackupVolumeJob::class);

        Artisan::call('schedule:list');
        expect(Artisan::output())->toContain('commypanel:backup')->and(BackupCommand::AT)->toBe('03:30');
    });

    it('prunes to the keep count and raises the backup alert on failure', function () {
        $this->host->putVolumeFile('shop', 'uploads', 'a.txt', 'x');
        $this->volume->forceFill(['nightly_backup' => true])->save();
        $this->host->failOn('backupVolume');

        Artisan::call('commypanel:backup');

        expect(Cache::get(BackupVolumeJob::pendingKey('shop', 'uploads')))->toHaveKey('failed');

        Artisan::call('commypanel:backup');

        expect(count(app(VolumeBackups::class)->all($this->shop)))->toBe(1);
    });
});

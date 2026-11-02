<?php

use App\Backups\BackupFailed;
use App\Backups\VolumeBackups;
use App\Deploy\DestroyApp;
use App\Host\Drivers\FakeHostDriver;
use App\Host\HostDriver;
use App\Jobs\BackupVolumeJob;
use App\Models\App;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;

beforeEach(function () {
    $this->dir = storage_path('framework/testing/volbackups-'.bin2hex(random_bytes(4)));
    config(['commypanel.data_path' => $this->dir, 'commypanel.backups.keep_volumes' => 2]);
    Sleep::fake();
    Cache::flush();

    $this->actingAs(User::factory()->create());
    $this->host = app(HostDriver::class);
    expect($this->host)->toBeInstanceOf(FakeHostDriver::class);
    $this->confirmed = fn () => $this->withSession(['auth.password_confirmed_at' => time()]);

    $this->shop = App::factory()->create(['slug' => 'shop']);
    $this->shop->volumes()->create(['name' => 'uploads', 'path' => '/app/uploads']);
});

afterEach(fn () => File::deleteDirectory($this->dir));

it('writes an archive that only the panel user can read', function () {
    $backup = app(VolumeBackups::class)->create($this->shop, 'uploads');

    $path = $this->dir.'/backups/volumes/shop/'.$backup['name'];

    expect($backup['name'])->toMatch('/^uploads-\d{8}-\d{6}\.tar\.gz$/')
        ->and($backup['volume'])->toBe('uploads')
        ->and(gzdecode((string) file_get_contents($path)))->toBe('fake archive of shop/uploads')
        ->and(decoct(fileperms($path) & 0777))->toBe('600')
        ->and(decoct(fileperms(dirname($path)) & 0777))->toBe('700');
});

it('keeps only the newest few of each volume', function () {
    $dir = $this->dir.'/backups/volumes/shop';
    mkdir($dir, 0700, true);
    foreach (['20261101-010101', '20261102-010101', '20261103-010101'] as $i => $stamp) {
        touch("{$dir}/uploads-{$stamp}.tar.gz", time() - (10 - $i));
    }
    touch("{$dir}/data-20261101-010101.tar.gz", time() - 50);

    app(VolumeBackups::class)->prune($this->shop, 'uploads', 2);

    expect(array_column(app(VolumeBackups::class)->all($this->shop), 'name'))
        ->toBe(['uploads-20261103-010101.tar.gz', 'uploads-20261102-010101.tar.gz', 'data-20261101-010101.tar.gz']);
});

it('says why a backup could not be made and leaves no partial file', function () {
    $this->host->failOn('backupVolume');

    expect(fn () => app(VolumeBackups::class)->create($this->shop, 'uploads'))
        ->toThrow(BackupFailed::class, 'Could not back up uploads');

    expect(glob($this->dir.'/backups/volumes/shop/*'))->toBe([]);
});

it('only knows file names it made itself, so a name can never point anywhere else', function () {
    foreach (['../x.tar.gz', 'uploads-1.tar.gz', 'uploads-20261101-010101.tar.gz/../../x', '.htaccess'] as $name) {
        expect(fn () => app(VolumeBackups::class)->path($this->shop, $name))->toThrow(BackupFailed::class);
    }
});

describe('from the volumes tab', function () {
    it('queues the backup of a volume and shows it as running', function () {
        Queue::fake();

        $this->post('/apps/shop/volumes/uploads/backup')->assertSessionHas('status');

        Queue::assertPushed(BackupVolumeJob::class, fn ($job) => $job->volume === 'uploads' && $job->appId === $this->shop->id);

        $this->getJson('/apps/shop/volume-backups')->assertOk()->assertJsonPath('pending.uploads.running', true);

        $this->post('/apps/shop/volumes/uploads/backup')->assertSessionHasErrors('volume');
        Queue::assertPushed(BackupVolumeJob::class, 1);
    });

    it('refuses a volume the app does not have', function () {
        $this->post('/apps/shop/volumes/nothing/backup')->assertNotFound();
    });

    it('runs the job, lists the archive and clears the running mark', function () {
        $this->post('/apps/shop/volumes/uploads/backup');

        $json = $this->getJson('/apps/shop/volume-backups')->assertOk();

        expect($json->json('backups.0.volume'))->toBe('uploads')
            ->and($json->json('pending'))->toBe([])
            ->and($json->json('keep'))->toBe(2)
            ->and(AuditLog::query()->where('action', 'volume.backed_up')->exists())->toBeTrue();
    });

    it('shows a failure until the next try', function () {
        $this->host->failOn('backupVolume');

        $this->post('/apps/shop/volumes/uploads/backup');

        $this->getJson('/apps/shop/volume-backups')->assertJsonPath('pending.uploads.failed', fn ($m) => str_contains($m, 'Could not back up'));
    });

    it('downloads and deletes only after the password, and records both', function () {
        $name = app(VolumeBackups::class)->create($this->shop, 'uploads')['name'];

        $this->get("/apps/shop/volume-backups/{$name}")->assertRedirect();
        $this->delete("/apps/shop/volume-backups/{$name}")->assertRedirect();
        expect(AuditLog::query()->where('action', 'volume.backup_downloaded')->count())->toBe(0)
            ->and(app(VolumeBackups::class)->all($this->shop))->not->toBe([]);

        $response = ($this->confirmed)()->get("/apps/shop/volume-backups/{$name}")->assertOk();
        expect($response->headers->get('Cache-Control'))->toContain('no-store')
            ->and(AuditLog::query()->where('action', 'volume.backup_downloaded')->first()->metadata)->toBe(['app' => 'shop', 'file' => $name]);

        ($this->confirmed)()->delete("/apps/shop/volume-backups/{$name}")->assertSessionHas('status');
        expect(app(VolumeBackups::class)->all($this->shop))->toBe([])
            ->and(AuditLog::query()->where('action', 'volume.backup_deleted')->exists())->toBeTrue();
    });

    it('needs the volumes permission', function () {
        $this->actingAs(User::factory()->create(['is_super' => false]));

        $this->getJson('/apps/shop/volume-backups')->assertForbidden();
        $this->post('/apps/shop/volumes/uploads/backup')->assertForbidden();
    });
});

it('removes the archives of an app when the app is deleted', function () {
    app(VolumeBackups::class)->create($this->shop, 'uploads');
    expect(is_dir($this->dir.'/backups/volumes/shop'))->toBeTrue();

    app(DestroyApp::class)($this->shop);

    expect(is_dir($this->dir.'/backups/volumes/shop'))->toBeFalse();
});
